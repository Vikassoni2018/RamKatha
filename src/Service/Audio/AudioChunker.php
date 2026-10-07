<?php

declare(strict_types=1);

namespace App\Service\Audio;

/**
 * Splits narration text into TTS-friendly chunks of at most $maxChars characters, cutting at
 * sentence boundaries (। ! ? . newline), then at clause boundaries (, ; :), then at spaces.
 * Words are never split.
 */
final class AudioChunker
{
    /** @return list<string> */
    public function split(string $text, int $maxChars = 900): array
    {
        $maxChars = max(80, $maxChars);
        $text = trim($text);
        if ('' === $text) {
            return [];
        }

        $sentences = preg_split('/(?<=[।!?.])\s+|\n+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [$text];

        $chunks = [];
        $current = '';
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ('' === $sentence) {
                continue;
            }
            foreach ($this->splitLong($sentence, $maxChars) as $piece) {
                $candidate = '' === $current ? $piece : $current.' '.$piece;
                if (mb_strlen($candidate) <= $maxChars) {
                    $current = $candidate;
                } else {
                    if ('' !== $current) {
                        $chunks[] = $current;
                    }
                    $current = $piece;
                }
            }
        }
        if ('' !== $current) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** @return list<string> */
    private function splitLong(string $sentence, int $maxChars): array
    {
        if (mb_strlen($sentence) <= $maxChars) {
            return [$sentence];
        }

        // Try clause boundaries first.
        $clauses = preg_split('/(?<=[,;:])\s+/u', $sentence, -1, \PREG_SPLIT_NO_EMPTY) ?: [$sentence];
        $pieces = [];
        $current = '';
        foreach ($clauses as $clause) {
            if (mb_strlen($clause) > $maxChars) {
                if ('' !== $current) {
                    $pieces[] = $current;
                    $current = '';
                }
                array_push($pieces, ...$this->splitOnSpaces($clause, $maxChars));
                continue;
            }
            $candidate = '' === $current ? $clause : $current.' '.$clause;
            if (mb_strlen($candidate) <= $maxChars) {
                $current = $candidate;
            } else {
                $pieces[] = $current;
                $current = $clause;
            }
        }
        if ('' !== $current) {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /** @return list<string> */
    private function splitOnSpaces(string $text, int $maxChars): array
    {
        $words = preg_split('/\s+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $pieces = [];
        $current = '';
        foreach ($words as $word) {
            // A single "word" longer than the limit is hard-cut as a last resort.
            while (mb_strlen($word) > $maxChars) {
                if ('' !== $current) {
                    $pieces[] = $current;
                    $current = '';
                }
                $pieces[] = mb_substr($word, 0, $maxChars);
                $word = mb_substr($word, $maxChars);
            }
            $candidate = '' === $current ? $word : $current.' '.$word;
            if (mb_strlen($candidate) <= $maxChars) {
                $current = $candidate;
            } else {
                $pieces[] = $current;
                $current = $word;
            }
        }
        if ('' !== $current) {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
