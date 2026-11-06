<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Providers;

use JzGuilherme\SeoPipeline\Contracts\AiProviderInterface;
use JzGuilherme\SeoPipeline\DTO\ProductInputDTO;
use JzGuilherme\SeoPipeline\Exceptions\AiGenerationException;

/**
 * Google Gemini 2.5 Flash AI Provider using native Structured Outputs (responseSchema).
 * Ensures deterministic JSON schema compliance and extracts token usage metrics.
 */
final class GeminiAiProvider implements AiProviderInterface
{
    private const ENDPOINT_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 30
    ) {
        if (trim($this->apiKey) === '') {
            throw new AiGenerationException('GEMINI_API_KEY cannot be empty.', 401, false);
        }
    }

    /**
     * @inheritDoc
     */
    public function generateSeoData(ProductInputDTO $product): array
    {
        $payload = $this->buildRequestPayload($product);
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $ch = curl_init(self::ENDPOINT_URL);
        if ($ch === false) {
            throw new AiGenerationException('Failed to initialize cURL session for Gemini API.', 500, true);
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $rawResponse = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawResponse === false || $curlErrno !== 0) {
            throw new AiGenerationException(
                sprintf('cURL communication error (%d): %s', $curlErrno, $curlError),
                503,
                true
            );
        }

        $responseString = (string) $rawResponse;

        if ($httpCode < 200 || $httpCode >= 300) {
            throw AiGenerationException::fromHttpError($httpCode, $responseString);
        }

        /** @var array<string, mixed>|null $decodedResponse */
        $decodedResponse = json_decode($responseString, true);
        if (!is_array($decodedResponse)) {
            throw AiGenerationException::fromInvalidSchema('Top-level Gemini API response is not valid JSON.');
        }

        $candidateText = $decodedResponse['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($candidateText) || trim($candidateText) === '') {
            throw AiGenerationException::fromInvalidSchema('Missing candidates[0].content.parts[0].text in Gemini response.');
        }

        /** @var array<string, mixed>|null $structuredSeo */
        $structuredSeo = json_decode($candidateText, true);
        if (!is_array($structuredSeo)) {
            throw AiGenerationException::fromInvalidSchema('Candidate text payload is not a valid JSON object.');
        }

        $requiredKeys = ['meta_title', 'meta_description', 'focus_keyword', 'product_description', 'faqs'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $structuredSeo)) {
                throw AiGenerationException::fromInvalidSchema(sprintf('Missing required schema key "%s".', $key));
            }
        }

        $usageMetadata = is_array($decodedResponse['usageMetadata'] ?? null) ? $decodedResponse['usageMetadata'] : [];
        $promptTokens = (int) ($usageMetadata['promptTokenCount'] ?? 0);
        $completionTokens = (int) ($usageMetadata['candidatesTokenCount'] ?? 0);

        /** @var array<int, array{question: string, answer: string}> $faqs */
        $faqs = is_array($structuredSeo['faqs']) ? $structuredSeo['faqs'] : [];

        return [
            'meta_title'          => (string) $structuredSeo['meta_title'],
            'meta_description'    => (string) $structuredSeo['meta_description'],
            'focus_keyword'       => (string) $structuredSeo['focus_keyword'],
            'product_description' => (string) $structuredSeo['product_description'],
            'faqs'                => $faqs,
            'prompt_tokens'       => $promptTokens,
            'completion_tokens'   => $completionTokens,
        ];
    }

    /**
     * Builds the Gemini API request payload with strict JSON responseSchema.
     *
     * @return array<string, mixed>
     */
    public function buildRequestPayload(ProductInputDTO $product): array
    {
        $specsLines = [];
        foreach ($product->technicalSpecifications as $key => $val) {
            $specsLines[] = sprintf('- %s: %s', $key, $val);
        }

        $prompt = implode("\n", [
            'Act as a Senior E-commerce Technical SEO Specialist.',
            'Generate conversion-oriented SEO metadata, semantic HTML product copy, and technical FAQs strictly grounded in the provided product facts.',
            'NEVER invent technical specifications not listed below.',
            'Strictly adhere to these constraints:',
            '1. meta_title: Maximum 60 characters, compelling and keyword-focused.',
            '2. meta_description: Maximum 155 characters, action-oriented with clear value proposition.',
            '3. focus_keyword: Primary high-intent search term.',
            '4. product_description: Semantic HTML using ONLY <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong>, <em> tags without any inline attributes.',
            '5. faqs: 3 to 5 technical Question & Answer pairs based strictly on the specifications.',
            '',
            '--- PRODUCT DATA ---',
            sprintf('Product Name: %s', $product->productName),
            sprintf('Category: %s', $product->category),
            sprintf('Brand: %s', $product->brand ?? 'N/A'),
            sprintf('Target Audience: %s', $product->targetAudience ?? 'General E-commerce Buyers'),
            "Technical Specifications:\n" . implode("\n", $specsLines),
        ]);

        return [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'      => 0.2,
                'topP'             => 0.85,
                'responseMimeType' => 'application/json',
                'responseSchema'   => [
                    'type'       => 'OBJECT',
                    'required'   => [
                        'meta_title',
                        'meta_description',
                        'focus_keyword',
                        'product_description',
                        'faqs',
                    ],
                    'properties' => [
                        'meta_title' => [
                            'type'        => 'STRING',
                            'description' => 'SEO Meta Title with at most 60 characters.',
                        ],
                        'meta_description' => [
                            'type'        => 'STRING',
                            'description' => 'SEO Meta Description with at most 155 characters.',
                        ],
                        'focus_keyword' => [
                            'type'        => 'STRING',
                            'description' => 'Primary focus keyword for SEO.',
                        ],
                        'product_description' => [
                            'type'        => 'STRING',
                            'description' => 'Semantic HTML description using only h2, h3, p, ul, ol, li, strong, em.',
                        ],
                        'faqs' => [
                            'type'        => 'ARRAY',
                            'description' => 'List of frequently asked questions and answers about the product.',
                            'items'       => [
                                'type'       => 'OBJECT',
                                'required'   => ['question', 'answer'],
                                'properties' => [
                                    'question' => ['type' => 'STRING'],
                                    'answer'   => ['type' => 'STRING'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
