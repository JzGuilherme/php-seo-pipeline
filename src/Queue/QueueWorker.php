<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Queue;

use JzGuilherme\SeoPipeline\Contracts\PlatformAdapterInterface;
use JzGuilherme\SeoPipeline\DTO\ProductInputDTO;
use JzGuilherme\SeoPipeline\Services\SeoContentGenerator;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Concurrent MySQL 8.0 Queue Worker using `SELECT ... FOR UPDATE SKIP LOCKED`,
 * exponential backoff (`pow(4, $attempts) * 10`), PCNTL graceful shutdown, and a 128MB memory guard.
 */
final class QueueWorker
{
    private const DEFAULT_MEMORY_LIMIT_BYTES = 134217728; // 128 MB

    private bool $shouldStop = false;

    /** @var array<string, PlatformAdapterInterface> */
    private array $adapters = [];

    /**
     * @param array<int, PlatformAdapterInterface> $platformAdapters
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly SeoContentGenerator $generator,
        array $platformAdapters = [],
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $memoryLimitBytes = self::DEFAULT_MEMORY_LIMIT_BYTES
    ) {
        foreach ($platformAdapters as $adapter) {
            $this->registerAdapter($adapter);
        }
    }

    public function registerAdapter(PlatformAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->getPlatformName()] = $adapter;
    }

    /**
     * Starts the worker daemon loop with PCNTL signal handling and preventive memory guard.
     */
    public function work(int $sleepSeconds = 3, int $maxJobs = 0): int
    {
        $this->registerSignalHandlers();
        $processedCount = 0;

        $this->logger->info('QueueWorker daemon started.', [
            'memory_limit_mb' => round($this->memoryLimitBytes / 1048576, 2),
            'sleep_seconds'   => $sleepSeconds,
        ]);

        while (!$this->shouldStop) {
            $this->dispatchSignals();

            if ($this->isMemoryLimitExceeded()) {
                $this->logger->warning('Preventive memory limit (128MB) reached. Stopping worker gracefully for supervisor restart.', [
                    'memory_usage_bytes' => memory_get_usage(true),
                ]);
                break;
            }

            $processed = $this->processNextJob();

            if ($processed) {
                $processedCount++;
                if ($maxJobs > 0 && $processedCount >= $maxJobs) {
                    $this->logger->info('Reached configured maxJobs limit. Stopping worker.', ['processed' => $processedCount]);
                    break;
                }
            } else {
                // Sleep in 1-second ticks to remain responsive to SIGTERM/SIGINT
                for ($i = 0; $i < max(1, $sleepSeconds) && !$this->shouldStop; $i++) {
                    sleep(1);
                    $this->dispatchSignals();
                }
            }
        }

        $this->logger->info('QueueWorker daemon exited cleanly.', ['total_processed' => $processedCount]);

        return $processedCount;
    }

    /**
     * Atomically claims and processes the next available job using `SELECT ... FOR UPDATE SKIP LOCKED`.
     */
    public function processNextJob(): bool
    {
        $job = $this->claimNextJob();
        if ($job === null) {
            return false;
        }

        $jobId = (int) $job['id'];
        $attempts = (int) $job['attempts'];
        $maxAttempts = (int) $job['max_attempts'];
        $externalId = (string) $job['external_product_id'];
        $platform = (string) $job['platform'];

        $this->logger->info('Processing SEO job.', [
            'job_id'              => $jobId,
            'external_product_id' => $externalId,
            'platform'            => $platform,
            'attempt'             => $attempts,
        ]);

        try {
            /** @var array<string, mixed>|null $payloadData */
            $payloadData = json_decode((string) $job['payload'], true);
            if (!is_array($payloadData)) {
                throw new \RuntimeException('Corrupted JSON payload in seo_job_queue.');
            }

            $productInput = ProductInputDTO::fromArray($payloadData);
            $seoOutput = $this->generator->generate($productInput, true);

            if (isset($this->adapters[$platform])) {
                $this->adapters[$platform]->syncProductSeo($externalId, $seoOutput);
            }

            $this->markAsCompleted($jobId);

            $this->logger->info('SEO job completed successfully.', [
                'job_id'            => $jobId,
                'prompt_tokens'     => $seoOutput->promptTokens,
                'completion_tokens' => $seoOutput->completionTokens,
            ]);
        } catch (Throwable $e) {
            $this->handleJobFailure($jobId, $attempts, $maxAttempts, $e);
        }

        return true;
    }

    /**
     * Claims a single pending/retryable row with `FOR UPDATE SKIP LOCKED` and marks it as `processing`.
     *
     * @return array<string, mixed>|null
     */
    private function claimNextJob(): ?array
    {
        $this->pdo->beginTransaction();

        try {
            $selectSql = <<<'SQL'
                SELECT id, external_product_id, platform, payload, attempts, max_attempts
                FROM seo_job_queue
                WHERE status = 'pending'
                  AND attempts < max_attempts
                  AND (next_retry_at IS NULL OR next_retry_at <= NOW())
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            SQL;

            $stmt = $this->pdo->query($selectSql);
            /** @var array<string, mixed>|false $job */
            $job = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

            if ($job === false || $job === []) {
                $this->pdo->commit();
                return null;
            }

            $newAttempts = ((int) $job['attempts']) + 1;
            $updateStmt = $this->pdo->prepare(
                "UPDATE seo_job_queue SET status = 'processing', attempts = :attempts, updated_at = NOW() WHERE id = :id"
            );
            $updateStmt->execute([
                ':attempts' => $newAttempts,
                ':id'       => $job['id'],
            ]);

            $this->pdo->commit();
            $job['attempts'] = $newAttempts;

            return $job;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function markAsCompleted(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE seo_job_queue SET status = 'completed', error_message = NULL, next_retry_at = NULL, updated_at = NOW() WHERE id = :id"
        );
        $stmt->execute([':id' => $jobId]);
    }

    /**
     * Applies Exponential Backoff (`pow(4, $attempts) * 10` seconds) or marks job as permanently `failed`.
     */
    private function handleJobFailure(int $jobId, int $attempts, int $maxAttempts, Throwable $exception): void
    {
        $errorMessage = mb_substr($exception->getMessage(), 0, 2000);

        if ($attempts >= $maxAttempts) {
            $stmt = $this->pdo->prepare(
                "UPDATE seo_job_queue SET status = 'failed', error_message = :error, updated_at = NOW() WHERE id = :id"
            );
            $stmt->execute([
                ':error' => $errorMessage,
                ':id'    => $jobId,
            ]);

            $this->logger->error('SEO job permanently failed after reaching max attempts.', [
                'job_id'   => $jobId,
                'attempts' => $attempts,
                'error'    => $errorMessage,
            ]);
            return;
        }

        $backoffSeconds = $this->calculateBackoffSeconds($attempts);

        $stmt = $this->pdo->prepare(
            "UPDATE seo_job_queue
             SET status = 'pending',
                 error_message = :error,
                 next_retry_at = DATE_ADD(NOW(), INTERVAL :backoff SECOND),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            ':error'   => $errorMessage,
            ':backoff' => $backoffSeconds,
            ':id'      => $jobId,
        ]);

        $this->logger->warning('SEO job failed; scheduled for retry with exponential backoff.', [
            'job_id'          => $jobId,
            'attempt'         => $attempts,
            'backoff_seconds' => $backoffSeconds,
            'error'           => $errorMessage,
        ]);
    }

    /**
     * Exponential backoff formula: `pow(4, $attempts) * 10` seconds.
     */
    public function calculateBackoffSeconds(int $attempts): int
    {
        return (int) (pow(4, max(1, $attempts)) * 10);
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    private function isMemoryLimitExceeded(): bool
    {
        return memory_get_usage(true) >= $this->memoryLimitBytes;
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signal): void {
            $this->logger->info('Received shutdown signal. Finishing current job before exit.', ['signal' => $signal]);
            $this->shouldStop = true;
        };

        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, $handler);
        }
        if (defined('SIGINT')) {
            pcntl_signal(SIGINT, $handler);
        }
        if (defined('SIGQUIT')) {
            pcntl_signal(SIGQUIT, $handler);
        }
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}
