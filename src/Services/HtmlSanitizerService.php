<?php

declare(strict_types=1);

namespace JzGuilherme\SeoPipeline\Services;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitizes AI-generated HTML descriptions to prevent Stored XSS via Prompt Injection.
 * Enforces a strict semantic tag whitelist (h2, h3, p, ul, ol, li, strong, em) with zero inline attributes.
 */
final class HtmlSanitizerService
{
    private const ALLOWED_TAGS = ['h2', 'h3', 'p', 'ul', 'ol', 'li', 'strong', 'em'];

    private ?HTMLPurifier $purifier = null;

    public function __construct()
    {
        if (class_exists(HTMLPurifier_Config::class) && class_exists(HTMLPurifier::class)) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('Core.Encoding', 'UTF-8');
            $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
            // Strictly allow only semantic tags with NO attributes (no style, class, id, onclick, etc.)
            $config->set('HTML.Allowed', implode(',', self::ALLOWED_TAGS));
            $config->set('Cache.DefinitionImpl', null);

            $this->purifier = new HTMLPurifier($config);
        }
    }

    /**
     * Sanitizes HTML string keeping only the strict whitelist of semantic tags without attributes.
     */
    public function sanitizeHtml(string $dirtyHtml): string
    {
        if (trim($dirtyHtml) === '') {
            return '';
        }

        // Remove dangerous blocks along with their inner content first (<script>, <style>, <iframe>, <object>, <svg>)
        $cleaned = (string) preg_replace(
            '#<(script|style|iframe|object|embed|svg|form|meta|link)\b[^>]*>.*?</\1>#is',
            '',
            $dirtyHtml
        );

        // Remove self-closing or unclosed dangerous tags
        $cleaned = (string) preg_replace(
            '#<(script|style|iframe|object|embed|svg|form|meta|link|img|input|button)\b[^>]*\/?>#is',
            '',
            $cleaned
        );

        if ($this->purifier !== null) {
            $cleaned = $this->purifier->purify($cleaned);
        } else {
            $allowedTagsString = '<' . implode('><', self::ALLOWED_TAGS) . '>';
            $cleaned = strip_tags($cleaned, $allowedTagsString);
        }

        // Enforce zero inline attributes on all remaining whitelisted tags (e.g., <h2 class="x" onclick="y"> -> <h2>)
        $tagPattern = implode('|', self::ALLOWED_TAGS);
        $cleaned = (string) preg_replace(
            '#<(' . $tagPattern . ')\b[^>]*>#i',
            '<$1>',
            $cleaned
        );

        // Normalize closing tags to lowercase and trim redundant whitespace
        $cleaned = (string) preg_replace_callback(
            '#</?(' . $tagPattern . ')>#i',
            static fn (array $matches): string => mb_strtolower($matches[0]),
            $cleaned
        );

        return trim($cleaned);
    }

    /**
     * Strips all HTML tags and normalizes whitespace for plain-text SEO meta fields.
     */
    public function sanitizePlainText(string $text): string
    {
        $withoutScripts = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $text);
        $stripped = strip_tags(html_entity_decode($withoutScripts, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $normalized = (string) preg_replace('/\s+/u', ' ', $stripped);

        return trim($normalized);
    }
}
