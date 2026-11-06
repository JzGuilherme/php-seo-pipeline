<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\DTO;

use JsonSerializable;

/**
 * Immutable Data Transfer Object holding the sanitized, length-validated SEO output
 * along with Schema.org FAQPage JSON-LD and token usage telemetry.
 */
final readonly class SeoOutputDTO implements JsonSerializable
{
    /**
     * @param string $metaTitle Validated Meta Title (<= 60 chars)
     * @param string $metaDescription Validated Meta Description (<= 155 chars)
     * @param string $focusKeyword Primary SEO focus keyword
     * @param string $productDescription Sanitized semantic HTML product description
     * @param array<int, array{question: string, answer: string}> $faqs Sanitized Q&A pairs
     * @param array<string, mixed> $faqSchemaJsonLd Complete Schema.org FAQPage JSON-LD array
     * @param int $promptTokens Input token count reported by the LLM
     * @param int $completionTokens Output token count reported by the LLM
     */
    public function __construct(
        public string $metaTitle,
        public string $metaDescription,
        public string $focusKeyword,
        public string $productDescription,
        public array $faqs,
        public array $faqSchemaJsonLd,
        public int $promptTokens = 0,
        public int $completionTokens = 0
    ) {
    }

    public function getFaqJsonLdScriptTag(): string
    {
        $json = json_encode(
            $this->faqSchemaJsonLd,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );

        return sprintf("<script type=\"application/ld+json\">\n%s\n</script>", $json);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'meta_title'          => $this->metaTitle,
            'meta_description'    => $this->metaDescription,
            'focus_keyword'       => $this->focusKeyword,
            'product_description' => $this->productDescription,
            'faqs'                => $this->faqs,
            'faq_schema_jsonld'   => $this->faqSchemaJsonLd,
            'prompt_tokens'       => $this->promptTokens,
            'completion_tokens'   => $this->completionTokens,
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
