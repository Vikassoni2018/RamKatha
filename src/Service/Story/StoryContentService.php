<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\Story;
use App\Service\Content\HtmlContentSanitizer;

/**
 * Derives stored values from story content: sanitised bodies (Hindi and English), word counts,
 * reading times and the plain-text search document used by the FULLTEXT index. The search document
 * holds both languages, so a story is found by Hindi and English queries alike.
 */
final class StoryContentService
{
    /** Average Hindi reading speed (words per minute) used for the reading-time estimate. */
    public const WORDS_PER_MINUTE = 180;

    /** Average English reading speed (words per minute). */
    public const WORDS_PER_MINUTE_EN = 220;

    public function __construct(private readonly HtmlContentSanitizer $sanitizer)
    {
    }

    public function refreshDerivedFields(Story $story): void
    {
        $story->setBody($this->sanitizer->sanitize($story->getBody()));

        $plain = $this->sanitizer->toPlainText($story->getBody());
        $words = $this->countWords($plain);
        $story->setWordCount($words);
        $story->setReadingTimeMinutes((int) max(1, ceil($words / self::WORDS_PER_MINUTE)));

        $plainEn = '';
        if (null !== $story->getBodyEn()) {
            $story->setBodyEn($this->sanitizer->sanitize($story->getBodyEn()));
        }
        if (null !== $story->getBodyEn()) {
            $plainEn = $this->sanitizer->toPlainText($story->getBodyEn());
            $wordsEn = $this->countWords($plainEn);
            $story->setWordCountEn($wordsEn);
            $story->setReadingTimeMinutesEn((int) max(1, ceil($wordsEn / self::WORDS_PER_MINUTE_EN)));
        } else {
            $story->setWordCountEn(0);
            $story->setReadingTimeMinutesEn(0);
        }

        $story->setSearchText($this->buildSearchDocument($story, $plain, $plainEn));
    }

    public function countWords(string $plainText): int
    {
        $parts = preg_split('/[\s।॥,.;:!?\-—]+/u', $plainText, -1, \PREG_SPLIT_NO_EMPTY);

        return false === $parts ? 0 : \count($parts);
    }

    public function plainText(Story $story): string
    {
        return $this->sanitizer->toPlainText($story->getBody());
    }

    private function buildSearchDocument(Story $story, string $plainBody, string $plainBodyEn): string
    {
        $terms = [$story->getTitleEn(), $story->getSubtitleEn(), $story->getExcerptEn()];
        foreach ([$story->getCategories(), $story->getDeities(), $story->getTags(), $story->getFestivals()] as $collection) {
            foreach ($collection as $term) {
                $terms[] = $term->getName();
                if (null !== $term->getNameEn()) {
                    $terms[] = $term->getNameEn();
                }
            }
        }
        foreach ([$story->getScripture(), $story->getSeries()] as $term) {
            if (null !== $term) {
                $terms[] = $term->getName();
                if (null !== $term->getNameEn()) {
                    $terms[] = $term->getNameEn();
                }
            }
        }
        foreach ($story->getSourceReferences() as $reference) {
            $terms[] = $reference->getDisplayTitle();
        }

        return trim(implode(' ', array_unique(array_filter($terms)))."\n".$plainBody."\n".$plainBodyEn);
    }
}
