<?php

declare(strict_types=1);

namespace App\Service\Content;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Sanitises rich text (story bodies, pages) down to a small set of semantic tags:
 * p, br, h2, h3, h4, blockquote, strong, em, ul, ol, li, a[href|title], hr.
 * Scripts, styles, event handlers, images, iframes and javascript: URLs are removed.
 * Configuration lives in config/packages/framework.yaml (app.story_sanitizer).
 */
final class HtmlContentSanitizer
{
    public function __construct(private readonly HtmlSanitizerInterface $sanitizer)
    {
    }

    public function sanitize(?string $html): string
    {
        if (null === $html || '' === trim($html)) {
            return '';
        }

        // Normalise line endings and non-breaking spaces commonly pasted from word processors.
        $html = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $html);

        $clean = $this->sanitizer->sanitize($html);

        // Remove empty paragraphs/headings left over by pasted content.
        $clean = preg_replace('#<(p|h2|h3|h4|blockquote|li)>(\s|&nbsp;|<br\s*/?>)*</\1>#u', '', $clean) ?? $clean;
        // Collapse runs of <br> into a paragraph break.
        $clean = preg_replace('#(<br\s*/?>\s*){3,}#u', '<br /><br />', $clean) ?? $clean;

        return trim($clean);
    }

    /** Plain text version (for excerpts, search documents and TTS). */
    public function toPlainText(string $html): string
    {
        $text = preg_replace('#<(br|hr)\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#</(p|h2|h3|h4|blockquote|li|ul|ol)>#i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n\s*\n\s*(\n\s*)+/u", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
