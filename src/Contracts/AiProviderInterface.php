<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Contracts;

use JzGuilherme\SeoPipeline\DTO\ProductInputDTO;
use JzGuilherme\SeoPipeline\Exceptions\AiGenerationException;

/**
 * Contract for Generative AI Providers that return structured SEO content and token metrics.
 */
interface AiProviderInterface
{
    /**
     * Generates raw structured SEO metadata and content from product attributes.
     *
     * @param ProductInputDTO $product Validated product input data.
     * @return array{
     *     meta_title: string,
     *     meta_description: string,
     *     focus_keyword: string,
     *     product_description: string,
     *     faqs: array<int, array{question: string, answer: string}>,
     *     prompt_tokens: int,
     *     completion_tokens: int
     * }
     * @throws AiGenerationException When the API call fails, times out, or returns invalid schema.
     */
    public function generateSeoData(ProductInputDTO $product): array;
}
