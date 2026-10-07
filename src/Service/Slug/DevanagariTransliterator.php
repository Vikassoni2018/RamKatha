<?php

declare(strict_types=1);

namespace App\Service\Slug;

/**
 * Readable Devanagari → ASCII transliteration for URLs (Hindi conventions, not ISO-15919):
 * "श्री कृष्ण की बाल लीला" → "shri krishna ki bal lila".
 *
 * Implements the usual Hindi schwa-deletion heuristics:
 *  - the inherent "a" of a word-final consonant is dropped ("राम" → "ram"), except after a
 *    conjunct ("धर्म" → "dharma", "मंत्र" → "mantra") or in one-letter words;
 *  - a medial inherent "a" is dropped in a V C_ C V context ("सरकार" → "sarkar").
 * It is a heuristic: editors can always override a generated slug.
 */
final class DevanagariTransliterator
{
    private const VIRAMA = "\u{094D}";
    private const NUKTA = "\u{093C}";

    private const CONSONANTS = [
        'क' => 'k', 'ख' => 'kh', 'ग' => 'g', 'घ' => 'gh', 'ङ' => 'n',
        'च' => 'ch', 'छ' => 'chh', 'ज' => 'j', 'झ' => 'jh', 'ञ' => 'n',
        'ट' => 't', 'ठ' => 'th', 'ड' => 'd', 'ढ' => 'dh', 'ण' => 'n',
        'त' => 't', 'थ' => 'th', 'द' => 'd', 'ध' => 'dh', 'न' => 'n',
        'प' => 'p', 'फ' => 'ph', 'ब' => 'b', 'भ' => 'bh', 'म' => 'm',
        'य' => 'y', 'र' => 'r', 'ल' => 'l', 'ळ' => 'l', 'व' => 'v',
        'श' => 'sh', 'ष' => 'sh', 'स' => 's', 'ह' => 'h',
        // precomposed nukta letters
        'क़' => 'q', 'ख़' => 'kh', 'ग़' => 'gh', 'ज़' => 'z', 'ड़' => 'r', 'ढ़' => 'rh', 'फ़' => 'f', 'य़' => 'y',
    ];

    /** Base consonant + nukta (decomposed form) */
    private const NUKTA_FORMS = ['क' => 'q', 'ख' => 'kh', 'ग' => 'gh', 'ज' => 'z', 'ड' => 'r', 'ढ' => 'rh', 'फ' => 'f', 'य' => 'y'];

    private const INDEPENDENT_VOWELS = [
        'अ' => 'a', 'आ' => 'a', 'इ' => 'i', 'ई' => 'i', 'उ' => 'u', 'ऊ' => 'u',
        'ऋ' => 'ri', 'ॠ' => 'ri', 'ए' => 'e', 'ऐ' => 'ai', 'ओ' => 'o', 'औ' => 'au', 'ऑ' => 'o', 'ऍ' => 'e', 'ऌ' => 'li',
    ];

    private const MATRAS = [
        'ा' => 'a', 'ि' => 'i', 'ी' => 'i', 'ु' => 'u', 'ू' => 'u', 'ृ' => 'ri', 'ॄ' => 'ri',
        'े' => 'e', 'ै' => 'ai', 'ो' => 'o', 'ौ' => 'au', 'ॅ' => 'e', 'ॉ' => 'o',
    ];

    private const LABIALS = ['p', 'ph', 'b', 'bh', 'm'];

    public function transliterate(string $text): string
    {
        if (!preg_match('/\p{Devanagari}/u', $text)) {
            return $text;
        }
        $text = \Normalizer::isNormalized($text, \Normalizer::FORM_C) ? $text : (string) \Normalizer::normalize($text, \Normalizer::FORM_C);

        // Split into Devanagari words and everything else, transliterating word by word.
        return (string) preg_replace_callback(
            '/[\x{0900}-\x{097F}]+/u',
            fn (array $m) => $this->transliterateWord($m[0]),
            $text,
        );
    }

    private function transliterateWord(string $word): string
    {
        $units = $this->parse($word);
        $count = \count($units);
        if (0 === $count) {
            return ' ';
        }

        // Decide inherent "a" from right to left.
        for ($i = $count - 1; $i >= 0; --$i) {
            $unit = &$units[$i];
            if ('consonant' !== $unit['type'] || 'inherent' !== $unit['vowel']) {
                unset($unit);
                continue;
            }
            if ($i === $count - 1) {
                $afterCluster = $i > 0 && 'consonant' === $units[$i - 1]['type'] && 'virama' === $units[$i - 1]['vowel'];
                $unit['keepA'] = 1 === $this->syllableCount($units) || $afterCluster || '' !== $unit['coda'];
            } elseif (0 === $i) {
                $unit['keepA'] = true;
            } else {
                $prev = $units[$i - 1];
                $next = $units[$i + 1];
                $prevHasVowel = 'vowel' === $prev['type'] || ('consonant' === $prev['type'] && 'virama' !== $prev['vowel']);
                $nextHasVowel = 'consonant' === $next['type'] && (
                    ('matra' === $next['vowel']) || ('inherent' === $next['vowel'] && true === ($next['keepA'] ?? false))
                );
                $unit['keepA'] = !($prevHasVowel && $nextHasVowel && '' === $unit['coda'] && '' === $prev['coda']);
            }
            unset($unit);
        }

        $out = '';
        foreach ($units as $i => $unit) {
            if ('other' === $unit['type']) {
                $out .= $unit['latin'];
                continue;
            }
            $out .= $unit['latin'];
            if ('consonant' === $unit['type']) {
                if ('matra' === $unit['vowel']) {
                    $out .= $unit['matra'];
                } elseif ('inherent' === $unit['vowel'] && ($unit['keepA'] ?? true)) {
                    $out .= 'a';
                }
            }
            if ('' !== $unit['coda']) {
                $nextLatin = $units[$i + 1]['latin'] ?? '';
                $out .= 'anusvara' === $unit['coda']
                    ? (\in_array($nextLatin, self::LABIALS, true) || !isset($units[$i + 1]) ? 'm' : 'n')
                    : $unit['coda'];
            }
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $units */
    private function syllableCount(array $units): int
    {
        return \count(array_filter($units, static fn ($u) => 'vowel' === $u['type'] || ('consonant' === $u['type'] && 'virama' !== $u['vowel'])));
    }

    /** @return list<array<string, mixed>> */
    private function parse(string $word): array
    {
        $chars = preg_split('//u', $word, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $units = [];
        $n = \count($chars);
        for ($i = 0; $i < $n; ++$i) {
            $ch = $chars[$i];

            if (isset(self::CONSONANTS[$ch])) {
                $latin = self::CONSONANTS[$ch];
                if (self::NUKTA === ($chars[$i + 1] ?? null)) {
                    $latin = self::NUKTA_FORMS[$ch] ?? $latin;
                    ++$i;
                }
                // ज्ञ is pronounced "gy" in Hindi.
                if ('ज' === $ch && self::VIRAMA === ($chars[$i + 1] ?? null) && 'ञ' === ($chars[$i + 2] ?? null)) {
                    $latin = 'gy';
                    $i += 2;
                }
                $unit = ['type' => 'consonant', 'latin' => $latin, 'vowel' => 'inherent', 'matra' => '', 'coda' => ''];
                $next = $chars[$i + 1] ?? null;
                if (self::VIRAMA === $next) {
                    $unit['vowel'] = 'virama';
                    ++$i;
                } elseif (null !== $next && isset(self::MATRAS[$next])) {
                    $unit['vowel'] = 'matra';
                    $unit['matra'] = self::MATRAS[$next];
                    ++$i;
                }
                $units[] = $unit;
                continue;
            }

            if (isset(self::INDEPENDENT_VOWELS[$ch])) {
                $units[] = ['type' => 'vowel', 'latin' => self::INDEPENDENT_VOWELS[$ch], 'vowel' => 'own', 'matra' => '', 'coda' => ''];
                continue;
            }

            $last = \count($units) - 1;
            switch ($ch) {
                case 'ं':
                case 'ँ':
                    if ($last >= 0) {
                        $units[$last]['coda'] = 'anusvara';
                    }
                    continue 2;
                case 'ः':
                    if ($last >= 0) {
                        $units[$last]['coda'] = 'h';
                    }
                    continue 2;
                case 'ॐ':
                    $units[] = ['type' => 'other', 'latin' => 'om', 'vowel' => '', 'matra' => '', 'coda' => ''];
                    continue 2;
                case 'ऽ':
                case self::NUKTA:
                case self::VIRAMA:
                    continue 2;
                case '।':
                case '॥':
                    $units[] = ['type' => 'other', 'latin' => ' ', 'vowel' => '', 'matra' => '', 'coda' => ''];
                    continue 2;
            }

            // Devanagari digits ०-९
            $code = mb_ord($ch);
            if ($code >= 0x0966 && $code <= 0x096F) {
                $units[] = ['type' => 'other', 'latin' => (string) ($code - 0x0966), 'vowel' => '', 'matra' => '', 'coda' => ''];
                continue;
            }
            // Stray matra (without consonant): treat as vowel.
            if (isset(self::MATRAS[$ch])) {
                $units[] = ['type' => 'vowel', 'latin' => self::MATRAS[$ch], 'vowel' => 'own', 'matra' => '', 'coda' => ''];
            }
        }

        return $units;
    }
}
