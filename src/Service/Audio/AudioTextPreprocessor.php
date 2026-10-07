<?php

declare(strict_types=1);

namespace App\Service\Audio;

use App\Entity\Story;
use App\Service\Content\HtmlContentSanitizer;

/**
 * Turns a story into clean narration text:
 * HTML → plain text → punctuation normalisation → pronunciation dictionary.
 */
final class AudioTextPreprocessor
{
    public function __construct(
        private readonly HtmlContentSanitizer $sanitizer,
        private readonly PronunciationService $pronunciation,
    ) {
    }

    public function prepareStory(Story $story): string
    {
        $parts = [rtrim($story->getTitle(), '।.!? ').'।'];
        if (null !== $story->getSubtitle() && '' !== trim($story->getSubtitle())) {
            $parts[] = rtrim($story->getSubtitle(), '।.!? ').'।';
        }
        $parts[] = $this->sanitizer->toPlainText($story->getBody());

        return $this->prepare(implode("\n\n", $parts), $story->getLanguage());
    }

    public function prepare(string $text, string $language): string
    {
        $text = $this->normalize($text);

        return $this->normalizeWhitespace($this->pronunciation->apply($text, $language));
    }

    /** Language-agnostic cleanup; Devanagari punctuation is normalised to the danda. */
    public function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // Remove URLs and e-mail addresses (not meant to be read aloud).
        $text = preg_replace('#https?://\S+|www\.\S+#iu', '', $text) ?? $text;
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/u', '', $text) ?? $text;

        $map = [
            '॥' => '।',         // double danda → danda
            '||' => '।',
            '|' => '।',
            '…' => '।',
            '...' => '।',
            '—' => ', ',
            '–' => ', ',
            '“' => '', '”' => '', '„' => '', '"' => '',
            '‘' => '', '’' => '', '`' => '',
            '*' => '', '#' => '', '_' => ' ', '~' => '',
            '[' => '', ']' => '', '{' => '', '}' => '',
            '(' => ', ', ')' => ', ',
            '•' => '', '·' => '',
        ];
        $text = strtr($text, $map);

        // Verse numbers such as "।।47।।" or "॥ 2.47 ॥" leftovers: drop bare numbering between dandas.
        $text = preg_replace('/।\s*[\d\x{0966}-\x{096F}.]+\s*।/u', '।', $text) ?? $text;

        // Collapse repeated punctuation and fix spacing around it.
        $text = preg_replace('/([।!?.,;:])\s*(?:[।!?.,;:]\s*)+/u', '$1 ', $text) ?? $text;
        $text = preg_replace('/\s+([।!?.,;:])/u', '$1', $text) ?? $text;
        $text = preg_replace('/^[\s,;:]+/mu', '', $text) ?? $text;

        return $this->normalizeWhitespace($text);
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = preg_replace("/[ \t\x{200B}\x{FEFF}]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/ *\n */u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
