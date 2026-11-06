<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Queue;

use JzGuilherme\SeoPipeline\DTO\ProductInputDTO;
use PDO;
use Throwable;

/**
 * High-throughput, idempotent Queue Producer using PDO transactions and SHA-256 payload hashing.
 * Prevents duplicate job creation and redundant LLM API billing via `INSERT IGNORE` on `uk_product_hash`.
 */
final class QueueProducer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $defaultMaxAttempts = 5
    ) {
    }

    /**
     * Enqueues a single validated ProductInputDTO idempotently.
     *
     * @return bool True if inserted as a new job, false if deduplicated (already existed).
     */
    public function enqueue(ProductInputDTO $product): bool
    {
        return $this->enqueueBatch([$product])['inserted'] === 1;
    }

    /**
     * Enqueues a batch of validated ProductInputDTO instances inside a single atomic PDO transaction.
     *
     * @param array<int, ProductInputDTO> $products
     * @return array{inserted: int, skipped: int}
     */
    public function enqueueBatch(array $products): array
    {
        if ($products === []) {
            return ['inserted' => 0, 'skipped' => 0];
        }

        $sql = <<<'SQL'
            INSERT IGNORE INTO seo_job_queue (
                external_product_id,
                platform,
                payload,
                input_hash,
                status,
                attempts,
                max_attempts,
                created_at,
                updated_at
            ) VALUES (
                :external_product_id,
                :platform,
                :payload,
                :input_hash,
                'pending',
                0,
                :max_attempts,
                NOW(),
                NOW()
            )
        SQL;

        $inserted = 0;
        $skipped = 0;

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare($sql);

            foreach ($products as $product) {
                $payloadJson = json_encode(
                    $product->toArray(),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $inputHash = hash('sha256', $payloadJson);

                $stmt->execute([
                    ':external_product_id' => $product->externalProductId,
                    ':platform'            => $product->platform,
                    ':payload'             => $payloadJson,
                    ':input_hash'          => $inputHash,
                    ':max_attempts'        => $this->defaultMaxAttempts,
                ]);

                if ($stmt->rowCount() > 0) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'inserted' => $inserted,
            'skipped'  => $skipped,
        ];
    }
}
