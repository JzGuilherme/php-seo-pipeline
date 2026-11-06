<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Services;

use JzGuilherme\SeoPipeline\Contracts\AiProviderInterface;
use JzGuilherme\SeoPipeline\DTO\ProductInputDTO;
use JzGuilherme\SeoPipeline\DTO\SeoOutputDTO;
use PDO;

/**
 * Main orchestrator service that coordinates AI generation, HTML sanitization,
 * SEO character limit validation, JSON-LD FAQPage assembly, and database persistence.
 */
final class SeoContentGenerator
{
    public function __construct(
        private readonly AiProviderInterface $aiProvider,
        private readonly SeoValidatorService $validator,
        private readonly ?PDO $pdo = null
    ) {
    }

    /**
     * Generates, sanitizes, validates, and optionally persists enriched SEO content for a product.
     */
    public function generate(ProductInputDTO $product, bool $persist = true): SeoOutputDTO
    {
        $rawAiData = $this->aiProvider->generateSeoData($product);
        $seoOutput = $this->validator->validateAndBuildOutput($rawAiData);

        if ($persist && $this->pdo !== null) {
            $this->persistGeneration($product, $seoOutput);
        }

        return $seoOutput;
    }

    /**
     * Persists the validated SEO generation and token telemetry into `seo_ai_generations`.
     */
    public function persistGeneration(ProductInputDTO $product, SeoOutputDTO $seoOutput): int
    {
        if ($this->pdo === null) {
            return 0;
        }

        $sql = <<<'SQL'
            INSERT INTO seo_ai_generations (
                external_product_id,
                platform,
                product_name,
                meta_title,
                meta_description,
                focus_keyword,
                product_description,
                faqs,
                faq_schema_jsonld,
                prompt_tokens,
                completion_tokens,
                status,
                created_at
            ) VALUES (
                :external_product_id,
                :platform,
                :product_name,
                :meta_title,
                :meta_description,
                :focus_keyword,
                :product_description,
                :faqs,
                :faq_schema_jsonld,
                :prompt_tokens,
                :completion_tokens,
                'pending_review',
                NOW()
            )
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':external_product_id' => $product->externalProductId,
            ':platform'            => $product->platform,
            ':product_name'        => $product->productName,
            ':meta_title'          => $seoOutput->metaTitle,
            ':meta_description'    => $seoOutput->metaDescription,
            ':focus_keyword'       => $seoOutput->focusKeyword,
            ':product_description' => $seoOutput->productDescription,
            ':faqs'                => json_encode($seoOutput->faqs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':faq_schema_jsonld'   => json_encode($seoOutput->faqSchemaJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':prompt_tokens'       => $seoOutput->promptTokens,
            ':completion_tokens'   => $seoOutput->completionTokens,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
