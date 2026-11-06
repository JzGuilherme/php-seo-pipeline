<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Tests\Unit;

use JzGuilherme\SeoPipeline\DTO\ProductInputDTO;
use JzGuilherme\SeoPipeline\Exceptions\SeoValidationException;
use JzGuilherme\SeoPipeline\Services\HtmlSanitizerService;
use JzGuilherme\SeoPipeline\Services\SeoValidatorService;
use PHPUnit\Framework\TestCase;

final class SeoValidatorServiceTest extends TestCase
{
    private SeoValidatorService $validator;
    private HtmlSanitizerService $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizerService();
        $this->validator = new SeoValidatorService($this->sanitizer);
    }

    public function testValidatesAndBuildsCompleteSeoOutputWithFaqJsonLd(): void
    {
        $rawAiOutput = [
            'meta_title'          => 'Notebook Gamer UltraPro 16 RTX 4070 32GB | Alta Performance',
            'meta_description'    => 'Compre o Notebook Gamer UltraPro 16 com Intel Core i9, RTX 4070 8GB e tela QHD+ 240Hz. Desempenho extremo para jogos AAA e renderização 3D.',
            'focus_keyword'       => 'notebook gamer rtx 4070',
            'product_description' => '<h2 class="red" onclick="alert(1)">Desempenho Extremo</h2><p>Equipado com <strong>RTX 4070</strong> e <em>32GB DDR5</em>.</p><script>evil()</script><ul><li>Tela 240Hz</li><li>SSD 1TB NVMe</li></ul>',
            'faqs'                => [
                [
                    'question' => 'Qual é a placa de vídeo deste notebook?',
                    'answer'   => 'Possui uma NVIDIA GeForce RTX 4070 com 8GB GDDR6 dedicados.',
                ],
                [
                    'question' => 'A memória RAM é DDR5?',
                    'answer'   => 'Sim, acompanha 32GB DDR5 operando a 5600MHz em Dual Channel.',
                ],
            ],
            'prompt_tokens'       => 380,
            'completion_tokens'   => 245,
        ];

        $dto = $this->validator->validateAndBuildOutput($rawAiOutput);

        self::assertLessThanOrEqual(60, mb_strlen($dto->metaTitle, 'UTF-8'));
        self::assertLessThanOrEqual(155, mb_strlen($dto->metaDescription, 'UTF-8'));
        self::assertSame('notebook gamer rtx 4070', $dto->focusKeyword);
        self::assertSame(
            '<h2>Desempenho Extremo</h2><p>Equipado com <strong>RTX 4070</strong> e <em>32GB DDR5</em>.</p><ul><li>Tela 240Hz</li><li>SSD 1TB NVMe</li></ul>',
            $dto->productDescription
        );
        self::assertStringNotContainsString('<script>', $dto->productDescription);
        self::assertStringNotContainsString('onclick', $dto->productDescription);
        self::assertCount(2, $dto->faqs);
        self::assertSame('https://schema.org', $dto->faqSchemaJsonLd['@context']);
        self::assertSame('FAQPage', $dto->faqSchemaJsonLd['@type']);
        self::assertCount(2, $dto->faqSchemaJsonLd['mainEntity']);
        self::assertSame(380, $dto->promptTokens);
        self::assertSame(245, $dto->completionTokens);
    }

    public function testSmartTruncatesMetaTitleAndMetaDescriptionAtWordBoundaries(): void
    {
        $longTitle = 'Notebook Gamer UltraPro 16 Polegadas com Processador Intel Core i9 e Placa RTX 4070';
        $longDesc = 'Descubra o novo Notebook Gamer UltraPro 16 projetado especificamente para jogadores competitivos de eSports e criadores de conteúdo 3D que precisam de máxima estabilidade térmica e taxa de quadros.';

        $truncatedTitle = $this->validator->validateMetaTitle($longTitle);
        $truncatedDesc = $this->validator->validateMetaDescription($longDesc);

        self::assertLessThanOrEqual(SeoValidatorService::MAX_META_TITLE_LENGTH, mb_strlen($truncatedTitle, 'UTF-8'));
        self::assertLessThanOrEqual(SeoValidatorService::MAX_META_DESCRIPTION_LENGTH, mb_strlen($truncatedDesc, 'UTF-8'));
        self::assertSame('Notebook Gamer UltraPro 16 Polegadas com Processador Intel', $truncatedTitle);
        self::assertStringEndsNotWith(' ', $truncatedDesc);
    }

    public function testThrowsExceptionWhenProductInputHasFewerThanTwoSpecifications(): void
    {
        $this->expectException(SeoValidationException::class);
        $this->expectExceptionMessage('At least 2 non-empty technical specifications are required');

        new ProductInputDTO(
            externalProductId: 'SKU-100',
            platform: 'woocommerce',
            productName: 'Mouse Gamer RGB',
            category: 'Periféricos',
            specifications: [
                'Sensor' => 'Pixart 3395',
            ]
        );
    }

    public function testComputesDeterministicSha256IdempotencyHashRegardlessOfSpecificationOrder(): void
    {
        $dtoA = new ProductInputDTO(
            externalProductId: 'SKU-200',
            platform: 'magento',
            productName: 'Teclado Mecânico Pro',
            category: 'Periféricos',
            specifications: [
                'Switch'  => 'Cherry MX Red',
                'Layout'  => 'ABNT2',
                'Conexão' => 'USB-C Cabo Removível',
            ]
        );

        $dtoB = new ProductInputDTO(
            externalProductId: 'SKU-200',
            platform: 'magento',
            productName: 'Teclado Mecânico Pro',
            category: 'Periféricos',
            specifications: [
                'Conexão' => 'USB-C Cabo Removível',
                'Switch'  => 'Cherry MX Red',
                'Layout'  => 'ABNT2',
            ]
        );

        self::assertSame(64, strlen($dtoA->computeIdempotencyHash()));
        self::assertSame($dtoA->computeIdempotencyHash(), $dtoB->computeIdempotencyHash());
    }
}
