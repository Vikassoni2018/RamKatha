<?php

declare(strict_types=1);

namespace App\Tests\Unit\Audio;

use App\Entity\PronunciationRule;
use App\Repository\PronunciationRuleRepository;
use App\Service\Audio\AudioChunker;
use App\Service\Audio\AudioTextPreprocessor;
use App\Service\Audio\PronunciationService;
use App\Service\Content\HtmlContentSanitizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class AudioPipelineTest extends TestCase
{
    private function rule(string $source, string $replacement, int $priority = 0, bool $wholeWord = true, bool $case = false): PronunciationRule
    {
        return (new PronunciationRule())->setSourceText($source)->setReplacementText($replacement)->setPriority($priority)->setWholeWord($wholeWord)->setCaseSensitive($case);
    }

    private function pronunciation(array $rules = []): PronunciationService
    {
        $repo = $this->createStub(PronunciationRuleRepository::class);
        $repo->method('findEnabledForLanguage')->willReturn($rules);

        return new PronunciationService($repo);
    }

    public function testWholeWordRulesRespectDevanagariMatras(): void
    {
        $service = $this->pronunciation();
        $rule = $this->rule('राम', 'श्रीराम');

        // "रामायण" contains "राम" but is a different word: must not change.
        self::assertSame('श्रीराम ने रामायण पढ़ी', $service->applyRule('राम ने रामायण पढ़ी', $rule));
    }

    public function testRulesAreAppliedInPriorityOrder(): void
    {
        $service = $this->pronunciation();
        // Rules arrive sorted by priority (repository): "श्रीकृष्ण" is expanded before the generic "कृष्ण" rule runs.
        $rules = [$this->rule('श्रीकृष्ण', 'श्री कृष्ण', 10), $this->rule('कृष्ण', 'क्रिष्ण', 1)];

        self::assertSame('श्री क्रिष्ण आए', $service->applyRules('श्रीकृष्ण आए', $rules));
    }

    public function testReplacementIsLiteral(): void
    {
        $service = $this->pronunciation();
        self::assertSame('cost $1 \\n', $service->applyRule('cost X', $this->rule('X', '$1 \\n', 0, true, true)));
    }

    public function testCaseInsensitiveLatinRule(): void
    {
        $service = $this->pronunciation();
        self::assertSame('इस्कॉन में', $service->applyRule('ISKCON में', $this->rule('iskcon', 'इस्कॉन')));
    }

    public function testPreprocessorStripsHtmlAndNormalisesPunctuation(): void
    {
        $sanitizer = new HtmlContentSanitizer(new HtmlSanitizer((new HtmlSanitizerConfig())->allowSafeElements()));
        $pre = new AudioTextPreprocessor($sanitizer, $this->pronunciation([$this->rule('ॐ', 'ओम्', 100, false)]));

        $text = $pre->prepare("<p>ॐ नमः शिवाय॥ देखें https://example.com</p>\n<p>“शिव” — कल्याणकारी!!!</p>", 'hi');

        self::assertStringNotContainsString('<p>', $text);
        self::assertStringNotContainsString('https://', $text);
        self::assertStringNotContainsString('॥', $text);
        self::assertStringNotContainsString('“', $text);
        self::assertStringContainsString('ओम्', $text);
        self::assertStringContainsString('शिव, कल्याणकारी!', $text);
        self::assertStringNotContainsString('!!', $text);
    }

    public function testChunkerNeverExceedsLimitAndKeepsAllWords(): void
    {
        $sentence = 'यह एक लंबा वाक्य है जिसमें बहुत सारे शब्द हैं, और यह कई बार दोहराया गया है।';
        $text = str_repeat($sentence.' ', 40);
        $chunks = (new AudioChunker())->split($text, 200);

        self::assertGreaterThan(1, \count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(200, mb_strlen($chunk));
        }
        $normalize = static fn (string $s) => preg_replace('/\s+/u', ' ', trim($s));
        self::assertSame($normalize($text), $normalize(implode(' ', $chunks)), 'No text may be lost or reordered.');
    }

    public function testChunkerSplitsAtSentenceBoundaries(): void
    {
        $chunks = (new AudioChunker())->split(str_repeat('क', 70).'। '.str_repeat('ख', 70).'।', 100);

        self::assertCount(2, $chunks);
        self::assertStringEndsWith('।', $chunks[0]);
    }

    public function testChunkerHandlesEmptyText(): void
    {
        self::assertSame([], (new AudioChunker())->split('   '));
    }
}
