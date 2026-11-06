<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Contracts;

use JzGuilherme\SeoPipeline\DTO\SeoOutputDTO;

/**
 * Contract for mapping and synchronizing validated SEO metadata to e-commerce platforms.
 */
interface PlatformAdapterInterface
{
    /**
     * Returns the canonical platform identifier ('woocommerce' or 'magento').
     */
    public function getPlatformName(): string;

    /**
     * Transforms the validated SeoOutputDTO into the platform-specific REST/Database payload.
     *
     * @param string $externalProductId SKU or Product ID on the target platform.
     * @param SeoOutputDTO $seoOutput Validated and sanitized SEO output.
     * @return array<string, mixed>
     */
    public function mapToPlatformPayload(string $externalProductId, SeoOutputDTO $seoOutput): array;

    /**
     * Pushes the mapped SEO payload to the target e-commerce platform.
     *
     * @param string $externalProductId SKU or Product ID on the target platform.
     * @param SeoOutputDTO $seoOutput Validated and sanitized SEO output.
     * @return bool True on successful synchronization.
     */
    public function syncProductSeo(string $externalProductId, SeoOutputDTO $seoOutput): bool;
}
