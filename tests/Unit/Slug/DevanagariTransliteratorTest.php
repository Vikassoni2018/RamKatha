<?php

declare(strict_types=1);

namespace App\Tests\Unit\Slug;

use App\Service\Slug\DevanagariTransliterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class DevanagariTransliteratorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function titles(): iterable
    {
        yield 'final schwa deleted' => ['राम', 'ram'];
        yield 'schwa kept after conjunct' => ['धर्म', 'dharma'];
        yield 'conjunct + anusvara' => ['मंत्र', 'mantra'];
        yield 'medial schwa deletion' => ['सरकार', 'sarkar'];
        yield 'full title' => ['श्री कृष्ण की बाल लीला', 'shri-krishna-ki-bal-lila'];
        yield 'jnya' => ['ज्ञान', 'gyan'];
        yield 'anusvara before labial' => ['अंबा', 'amba'];
        yield 'visarga and om' => ['ॐ नमः शिवाय', 'om-namah-shivay'];
        yield 'mixed scripts and digits' => ['डेमो: Krishna 2024', 'demo-krishna-2024'];
        yield 'long compound' => ['गोवर्धन लीला', 'govardhan-lila'];
    }

    #[DataProvider('titles')]
    public function testTransliteratesToReadableSlugs(string $hindi, string $expected): void
    {
        $slug = strtolower((string) (new AsciiSlugger('en'))->slug((new DevanagariTransliterator())->transliterate($hindi)));

        self::assertSame($expected, $slug);
    }

    public function testLeavesLatinTextUntouched(): void
    {
        self::assertSame('Hello World', (new DevanagariTransliterator())->transliterate('Hello World'));
    }
}
