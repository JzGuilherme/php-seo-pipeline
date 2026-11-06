<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Tests\Unit;

use JzGuilherme\SeoPipeline\Adapters\Magento2Adapter;
use JzGuilherme\SeoPipeline\Adapters\WooCommerceAdapter;
use JzGuilherme\SeoPipeline\Contracts\AiProviderInterface;
use JzGuilherme\SeoPipeline\DTO\ProductInputDTO;
use JzGuilherme\SeoPipeline\DTO\SeoOutputDTO;
use JzGuilherme\SeoPipeline\Services\HtmlSanitizerService;
use JzGuilherme\SeoPipeline\Services\SeoContentGenerator;
use JzGuilherme\SeoPipeline\Services\SeoValidatorService;
use PHPUnit\Framework\TestCase;

final class PlatformAdaptersAndGeneratorTest extends TestCase
{
    public function testWooCommerceAdapterMapsYoastAndRankMathMetaKeysCorrectly(): void
    {
        $adapter = new WooCommerceAdapter();
        $seoOutput = $this->createSampleSeoOutput();

        $payload = $adapter->mapToPlatformPayload('WC-SKU-01', $seoOutput);

        self::assertSame('woocommerce', $adapter->getPlatformName());
        self::assertSame('WC-SKU-01', $payload['sku']);
        self::assertSame($seoOutput->productDescription, $payload['description']);
        self::assertIsArray($payload['meta_data']);

        $metaMap = [];
        foreach ($payload['meta_data'] as $entry) {
            if (is_array($entry) && isset($entry['key'])) {
                $metaMap[(string) $entry['key']] = $entry['value'] ?? null;
            }
        }

        self::assertSame($seoOutput->metaTitle, $metaMap['_yoast_wpseo_title']);
        self::assertSame($seoOutput->metaDescription, $metaMap['_yoast_wpseo_metadesc']);
        self::assertSame($seoOutput->focusKeyword, $metaMap['_yoast_wpseo_focuskw']);
        self::assertSame($seoOutput->metaTitle, $metaMap['rank_math_title']);
        self::assertSame($seoOutput->metaDescription, $metaMap['rank_math_description']);
        self::assertSame($seoOutput->focusKeyword, $metaMap['rank_math_focus_keyword']);
        self::assertTrue($adapter->syncProductSeo('WC-SKU-01', $seoOutput));
    }

    public function testMagento2AdapterMapsEavCustomAttributesCorrectly(): void
    {
        $adapter = new Magento2Adapter();
        $seoOutput = $this->createSampleSeoOutput();

        $payload = $adapter->mapToPlatformPayload('M2-SKU-99', $seoOutput);

        self::assertSame('magento', $adapter->getPlatformName());
        self::assertIsArray($payload['product']);
        self::assertSame('M2-SKU-99', $payload['product']['sku']);

        $attrMap = [];
        foreach ($payload['product']['custom_attributes'] as $attr) {
            if (is_array($attr) && isset($attr['attribute_code'])) {
                $attrMap[(string) $attr['attribute_code']] = $attr['value'] ?? null;
            }
        }

        self::assertSame($seoOutput->metaTitle, $attrMap['meta_title']);
        self::assertSame($seoOutput->focusKeyword, $attrMap['meta_keyword']);
        self::assertSame($seoOutput->metaDescription, $attrMap['meta_description']);
        self::assertSame($seoOutput->productDescription, $attrMap['description']);
        self::assertNotEmpty($attrMap['seo_faq_schema_jsonld']);
        self::assertTrue($adapter->syncProductSeo('M2-SKU-99', $seoOutput));
    }

    public function testSeoContentGeneratorOrchestratesProviderAndValidator(): void
    {
        $mockProvider = new class () implements AiProviderInterface {
            public function generateSeoData(ProductInputDTO $product): array
            {
                return [
                    'meta_title'          => $product->productName . ' | Oferta Oficial',
                    'meta_description'    => 'Adquira ' . $product->productName . ' na categoria ' . $product->category . ' com garantia oficial e envio rápido.',
                    'focus_keyword'       => mb_strtolower($product->productName, 'UTF-8'),
                    'product_description' => '<h2>Sobre o Produto</h2><p>Excelente performance técnica.</p>',
                    'faqs'                => [
                        [
                            'question' => 'Qual é a garantia?',
                            'answer'   => 'Garantia oficial de 12 meses.',
                        ],
                    ],
                    'prompt_tokens'       => 210,
                    'completion_tokens'   => 140,
                ];
            }
        };

        $validator = new SeoValidatorService(new HtmlSanitizerService());
        $generator = new SeoContentGenerator($mockProvider, $validator, null);

        $input = new ProductInputDTO(
            externalProductId: 'TEST-SKU-77',
            platform: 'woocommerce',
            productName: 'Monitor Gamer Curvo 34 WQHD',
            category: 'Monitores',
            specifications: [
                'Taxa de Atualização' => '165Hz',
                'Tempo de Resposta'   => '1ms GtG',
            ]
        );

        $output = $generator->generate($input, false);

        self::assertSame('Monitor Gamer Curvo 34 WQHD | Oferta Oficial', $output->metaTitle);
        self::assertSame(210, $output->promptTokens);
        self::assertSame(140, $output->completionTokens);
        self::assertStringContainsString('application/ld+json', $output->getFaqJsonLdScriptTag());
    }

    private function createSampleSeoOutput(): SeoOutputDTO
    {
        return new SeoOutputDTO(
            metaTitle: 'Cafeteira Espresso Barista Master 19 Bar Inox',
            metaDescription: 'Prepare cafés especiais com a Cafeteira Espresso Barista Master 19 Bar em aço inox com moedor integrado.',
            focusKeyword: 'cafeteira espresso 19 bar',
            productDescription: '<h2>Café de Especialidade</h2><p>Extração perfeita a 19 Bar.</p>',
            faqs: [
                [
                    'question' => 'Possui moedor integrado?',
                    'answer'   => 'Sim, conta com moedor cônico em aço temperado.',
                ],
            ],
            faqSchemaJsonLd: [
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => [],
            ],
            promptTokens: 300,
            completionTokens: 180
        );
    }
}
