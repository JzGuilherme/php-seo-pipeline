<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when an AI provider fails due to network timeouts, rate limits (429),
 * server errors (5xx), or malformed structured output.
 */
final class AiGenerationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatusCode = 0,
        public readonly bool $isRetryable = true,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatusCode, $previous);
    }

    public static function fromHttpError(int $statusCode, string $responseBody): self
    {
        $retryable = in_array($statusCode, [408, 429, 500, 502, 503, 504], true);

        return new self(
            sprintf('AI Provider returned HTTP %d: %s', $statusCode, mb_substr($responseBody, 0, 500)),
            $statusCode,
            $retryable
        );
    }

    public static function fromInvalidSchema(string $reason): self
    {
        return new self(
            sprintf('AI Provider response violated structured schema: %s', $reason),
            422,
            true
        );
    }
}
