<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when product input data or generated SEO output fails domain validation rules.
 */
final class SeoValidationException extends InvalidArgumentException
{
    public static function insufficientProductData(string $reason): self
    {
        return new self(sprintf('Insufficient product input data for AI SEO generation: %s', $reason));
    }

    public static function invalidSeoField(string $field, string $reason): self
    {
        return new self(sprintf('SEO validation failed for field "%s": %s', $field, $reason));
    }
}
