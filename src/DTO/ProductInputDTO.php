<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\DTO;

use JsonSerializable;
use JzGuilherme\SeoPipeline\Exceptions\SeoValidationException;

/**
 * Immutable Data Transfer Object representing product input data for SEO enrichment.
 * Enforces strict pre-flight validation to prevent LLM hallucinations.
 */
final readonly class ProductInputDTO implements JsonSerializable
{
    /** @var array<string, string> */
    public array $technicalSpecifications;

    /**
     * @param string $externalProductId SKU or platform ID
     * @param string $platform Target platform ('woocommerce' or 'magento')
     * @param string $productName Full product title
     * @param string $category Primary category path or name
     * @param array<string, scalar> $specifications Key-value map of technical specifications (min 2 required)
     * @param string|null $brand Optional brand or manufacturer name
     * @param string|null $targetAudience Optional target audience persona
     * @throws SeoValidationException
     */
    public function __construct(
        public string $externalProductId,
        public string $platform,
        public string $productName,
        public string $category,
        array $specifications,
        public ?string $brand = null,
        public ?string $targetAudience = null
    ) {
        if (trim($this->externalProductId) === '') {
            throw SeoValidationException::insufficientProductData('External product ID (SKU) cannot be empty.');
        }

        if (!in_array($this->platform, ['woocommerce', 'magento'], true)) {
            throw SeoValidationException::insufficientProductData(
                sprintf('Unsupported platform "%s". Allowed: woocommerce, magento.', $this->platform)
            );
        }

        if (mb_strlen(trim($this->productName)) < 3) {
            throw SeoValidationException::insufficientProductData('Product name must contain at least 3 characters.');
        }

        if (mb_strlen(trim($this->category)) < 2) {
            throw SeoValidationException::insufficientProductData('Product category cannot be empty.');
        }

        $normalizedSpecs = [];
        foreach ($specifications as $key => $value) {
            $cleanKey = trim((string) $key);
            $cleanValue = trim((string) $value);
            if ($cleanKey !== '' && $cleanValue !== '') {
                $normalizedSpecs[$cleanKey] = $cleanValue;
            }
        }

        if (count($normalizedSpecs) < 2) {
            throw SeoValidationException::insufficientProductData(
                'At least 2 non-empty technical specifications are required to prevent AI hallucination.'
            );
        }

        ksort($normalizedSpecs);
        $this->technicalSpecifications = $normalizedSpecs;
    }

    /**
     * Generates a deterministic SHA-256 hash of the normalized product attributes for queue idempotency.
     */
    public function computeIdempotencyHash(): string
    {
        $canonicalPayload = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $canonicalPayload);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, scalar> $specs */
        $specs = is_array($data['specifications'] ?? null) ? $data['specifications'] : [];

        return new self(
            externalProductId: (string) ($data['external_product_id'] ?? ''),
            platform: (string) ($data['platform'] ?? ''),
            productName: (string) ($data['product_name'] ?? ''),
            category: (string) ($data['category'] ?? ''),
            specifications: $specs,
            brand: isset($data['brand']) && $data['brand'] !== '' ? (string) $data['brand'] : null,
            targetAudience: isset($data['target_audience']) && $data['target_audience'] !== '' ? (string) $data['target_audience'] : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'external_product_id' => trim($this->externalProductId),
            'platform'            => $this->platform,
            'product_name'        => trim($this->productName),
            'category'            => trim($this->category),
            'brand'               => $this->brand !== null ? trim($this->brand) : null,
            'target_audience'     => $this->targetAudience !== null ? trim($this->targetAudience) : null,
            'specifications'      => $this->technicalSpecifications,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
