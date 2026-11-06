<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Services;

use JzGuilherme\SeoPipeline\DTO\SeoOutputDTO;
use JzGuilherme\SeoPipeline\Exceptions\SeoValidationException;

/**
 * Enforces strict SEO character limits (Meta Title <= 60, Meta Description <= 155)
 * with intelligent word-boundary truncation, and builds Schema.org FAQPage JSON-LD.
 */
final class SeoValidatorService
{
    public const MAX_META_TITLE_LENGTH = 60;
    public const MAX_META_DESCRIPTION_LENGTH = 155;
    public const MAX_FOCUS_KEYWORD_LENGTH = 100;

    public function __construct(
        private readonly HtmlSanitizerService $sanitizer = new HtmlSanitizerService()
    ) {
    }

    /**
     * Validates, sanitizes, and normalizes raw AI output into an immutable SeoOutputDTO.
     *
     * @param array<string, mixed> $rawAiOutput
     * @throws SeoValidationException
     */
    public function validateAndBuildOutput(array $rawAiOutput): SeoOutputDTO
    {
        $rawTitle = (string) ($rawAiOutput['meta_title'] ?? '');
        $rawDescription = (string) ($rawAiOutput['meta_description'] ?? '');
        $rawKeyword = (string) ($rawAiOutput['focus_keyword'] ?? '');
        $rawHtml = (string) ($rawAiOutput['product_description'] ?? '');
        $rawFaqs = is_array($rawAiOutput['faqs'] ?? null) ? $rawAiOutput['faqs'] : [];

        $metaTitle = $this->validateMetaTitle($rawTitle);
        $metaDescription = $this->validateMetaDescription($rawDescription);
        $focusKeyword = $this->validateFocusKeyword($rawKeyword);
        $productDescription = $this->sanitizer->sanitizeHtml($rawHtml);

        if ($productDescription === '') {
            throw SeoValidationException::invalidSeoField('product_description', 'Sanitized HTML description cannot be empty.');
        }

        $validatedFaqs = $this->validateFaqs($rawFaqs);
        $faqJsonLd = $this->buildFaqPageJsonLd($validatedFaqs);

        $promptTokens = max(0, (int) ($rawAiOutput['prompt_tokens'] ?? 0));
        $completionTokens = max(0, (int) ($rawAiOutput['completion_tokens'] ?? 0));

        return new SeoOutputDTO(
            metaTitle: $metaTitle,
            metaDescription: $metaDescription,
            focusKeyword: $focusKeyword,
            productDescription: $productDescription,
            faqs: $validatedFaqs,
            faqSchemaJsonLd: $faqJsonLd,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens
        );
    }

    /**
     * Ensures Meta Title is non-empty and <= 60 chars, truncating intelligently at word boundary if needed.
     */
    public function validateMetaTitle(string $metaTitle): string
    {
        $clean = $this->sanitizer->sanitizePlainText($metaTitle);

        if ($clean === '') {
            throw SeoValidationException::invalidSeoField('meta_title', 'Meta title cannot be empty.');
        }

        return $this->smartTruncate($clean, self::MAX_META_TITLE_LENGTH);
    }

    /**
     * Ensures Meta Description is non-empty and <= 155 chars, truncating intelligently at word boundary if needed.
     */
    public function validateMetaDescription(string $metaDescription): string
    {
        $clean = $this->sanitizer->sanitizePlainText($metaDescription);

        if ($clean === '') {
            throw SeoValidationException::invalidSeoField('meta_description', 'Meta description cannot be empty.');
        }

        return $this->smartTruncate($clean, self::MAX_META_DESCRIPTION_LENGTH);
    }

    /**
     * Validates Focus Keyword.
     */
    public function validateFocusKeyword(string $focusKeyword): string
    {
        $clean = $this->sanitizer->sanitizePlainText($focusKeyword);

        if ($clean === '') {
            throw SeoValidationException::invalidSeoField('focus_keyword', 'Focus keyword cannot be empty.');
        }

        return $this->smartTruncate($clean, self::MAX_FOCUS_KEYWORD_LENGTH);
    }

    /**
     * Truncates a string to a maximum character limit without splitting words in the middle.
     */
    public function smartTruncate(string $text, int $maxLength): string
    {
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }

        $slice = mb_substr($text, 0, $maxLength, 'UTF-8');
        $nextChar = mb_substr($text, $maxLength, 1, 'UTF-8');

        // If the cut lands outside a word, no word was split
        if ($nextChar !== ' ' && $nextChar !== '') {
            $lastSpacePos = mb_strrpos($slice, ' ', 0, 'UTF-8');
            if ($lastSpacePos !== false && $lastSpacePos > (int) ($maxLength * 0.5)) {
                $slice = mb_substr($slice, 0, $lastSpacePos, 'UTF-8');
            }
        }

        return rtrim($slice, " \t\n\r\0\x0B.,;:-–—|/");
    }

    /**
     * Sanitizes and validates FAQ items.
     *
     * @param array<int, mixed> $faqs
     * @return array<int, array{question: string, answer: string}>
     */
    public function validateFaqs(array $faqs): array
    {
        $cleanFaqs = [];

        foreach ($faqs as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = $this->sanitizer->sanitizePlainText((string) ($item['question'] ?? ''));
            $answer = $this->sanitizer->sanitizePlainText((string) ($item['answer'] ?? ''));

            if ($question !== '' && $answer !== '') {
                $cleanFaqs[] = [
                    'question' => $question,
                    'answer'   => $answer,
                ];
            }
        }

        if ($cleanFaqs === []) {
            throw SeoValidationException::invalidSeoField('faqs', 'At least one valid FAQ pair (question and answer) is required.');
        }

        return $cleanFaqs;
    }

    /**
     * Builds the official Schema.org FAQPage JSON-LD representation.
     *
     * @param array<int, array{question: string, answer: string}> $faqs
     * @return array<string, mixed>
     */
    public function buildFaqPageJsonLd(array $faqs): array
    {
        $mainEntity = [];

        foreach ($faqs as $faq) {
            $mainEntity[] = [
                '@type'          => 'Question',
                'name'           => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $faq['answer'],
                ],
            ];
        }

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $mainEntity,
        ];
    }
}
