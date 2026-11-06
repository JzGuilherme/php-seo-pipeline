<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Adapters;

use JzGuilherme\SeoPipeline\Contracts\PlatformAdapterInterface;
use JzGuilherme\SeoPipeline\DTO\SeoOutputDTO;
use RuntimeException;

/**
 * WooCommerce REST API v3 Adapter.
 * Maps enriched SEO fields to both Yoast SEO (`_yoast_wpseo_*`) and Rank Math (`rank_math_*`)
 * post meta keys, alongside native WooCommerce description and JSON-LD schema injection.
 */
final class WooCommerceAdapter implements PlatformAdapterInterface
{
    public function __construct(
        private readonly string $apiUrl = '',
        private readonly string $consumerKey = '',
        private readonly string $consumerSecret = '',
        private readonly int $timeoutSeconds = 20
    ) {
    }

    public function getPlatformName(): string
    {
        return 'woocommerce';
    }

    /**
     * Maps SeoOutputDTO to WooCommerce REST API v3 Product payload with Yoast SEO & Rank Math metadata.
     *
     * @return array<string, mixed>
     */
    public function mapToPlatformPayload(string $externalProductId, SeoOutputDTO $seoOutput): array
    {
        $jsonLdRaw = json_encode(
            $seoOutput->faqSchemaJsonLd,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return [
            'sku'               => $externalProductId,
            'description'       => $seoOutput->productDescription,
            'short_description' => sprintf('<p>%s</p>', htmlspecialchars($seoOutput->metaDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            'meta_data'         => [
                // Yoast SEO Meta Keys
                [
                    'key'   => '_yoast_wpseo_title',
                    'value' => $seoOutput->metaTitle,
                ],
                [
                    'key'   => '_yoast_wpseo_metadesc',
                    'value' => $seoOutput->metaDescription,
                ],
                [
                    'key'   => '_yoast_wpseo_focuskw',
                    'value' => $seoOutput->focusKeyword,
                ],
                // Rank Math SEO Meta Keys
                [
                    'key'   => 'rank_math_title',
                    'value' => $seoOutput->metaTitle,
                ],
                [
                    'key'   => 'rank_math_description',
                    'value' => $seoOutput->metaDescription,
                ],
                [
                    'key'   => 'rank_math_focus_keyword',
                    'value' => $seoOutput->focusKeyword,
                ],
                // Structured FAQ & Schema.org JSON-LD Meta Keys
                [
                    'key'   => '_seo_pipeline_faqs',
                    'value' => $seoOutput->faqs,
                ],
                [
                    'key'   => '_seo_pipeline_faq_jsonld',
                    'value' => $jsonLdRaw,
                ],
            ],
        ];
    }

    /**
     * Synchronizes the mapped payload with WooCommerce REST API v3 if credentials are configured.
     */
    public function syncProductSeo(string $externalProductId, SeoOutputDTO $seoOutput): bool
    {
        $payload = $this->mapToPlatformPayload($externalProductId, $seoOutput);

        if (trim($this->apiUrl) === '' || trim($this->consumerKey) === '' || trim($this->consumerSecret) === '') {
            // Dry-run / local persistence only mode when external webhook credentials are not configured
            return true;
        }

        $endpoint = sprintf('%s/products/%s', rtrim($this->apiUrl, '/'), rawurlencode($externalProductId));
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL for WooCommerce REST API.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->consumerKey . ':' . $this->consumerSecret,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
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
                sprintf('WooCommerce sync failed for product "%s" (HTTP %d): %s', $externalProductId, $httpCode, $error ?: (string) $response)
            );
        }

        return true;
    }
}
