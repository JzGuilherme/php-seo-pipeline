<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Adapters;

use JzGuilherme\SeoPipeline\Contracts\PlatformAdapterInterface;
use JzGuilherme\SeoPipeline\DTO\SeoOutputDTO;
use RuntimeException;

/**
 * Magento 2 REST API (V1/products/:sku) & EAV Custom Attributes Adapter.
 * Maps validated SEO fields to Magento 2 EAV attributes (`meta_title`, `meta_keyword`,
 * `meta_description`, `description`, and custom FAQ JSON-LD attributes).
 */
final class Magento2Adapter implements PlatformAdapterInterface
{
    public function __construct(
        private readonly string $baseUrl = '',
        private readonly string $accessToken = '',
        private readonly int $timeoutSeconds = 20
    ) {
    }

    public function getPlatformName(): string
    {
        return 'magento';
    }

    /**
     * Maps SeoOutputDTO to Magento 2 REST API `V1/products/:sku` EAV `custom_attributes` structure.
     *
     * @return array<string, mixed>
     */
    public function mapToPlatformPayload(string $externalProductId, SeoOutputDTO $seoOutput): array
    {
        $faqJsonLdString = json_encode(
            $seoOutput->faqSchemaJsonLd,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return [
            'product' => [
                'sku'               => $externalProductId,
                'custom_attributes' => [
                    [
                        'attribute_code' => 'meta_title',
                        'value'          => $seoOutput->metaTitle,
                    ],
                    [
                        'attribute_code' => 'meta_keyword',
                        'value'          => $seoOutput->focusKeyword,
                    ],
                    [
                        'attribute_code' => 'meta_description',
                        'value'          => $seoOutput->metaDescription,
                    ],
                    [
                        'attribute_code' => 'description',
                        'value'          => $seoOutput->productDescription,
                    ],
                    [
                        'attribute_code' => 'seo_faq_schema_jsonld',
                        'value'          => $faqJsonLdString,
                    ],
                ],
            ],
        ];
    }

    /**
     * Pushes the EAV attribute payload to Magento 2 REST API (`PUT /rest/V1/products/:sku`).
     */
    public function syncProductSeo(string $externalProductId, SeoOutputDTO $seoOutput): bool
    {
        $payload = $this->mapToPlatformPayload($externalProductId, $seoOutput);

        if (trim($this->baseUrl) === '' || trim($this->accessToken) === '') {
            // Dry-run / local persistence mode when Magento REST credentials are not configured
            return true;
        }

        $endpoint = sprintf('%s/products/%s', rtrim($this->baseUrl, '/'), rawurlencode($externalProductId));
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL for Magento 2 REST API.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(
                sprintf('Magento 2 sync failed for SKU "%s" (HTTP %d): %s', $externalProductId, $httpCode, $error ?: (string) $response)
            );
        }

        return true;
    }
}
