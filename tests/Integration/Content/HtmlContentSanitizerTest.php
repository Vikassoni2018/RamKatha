<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Service\Content\HtmlContentSanitizer;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Uses the real "app.story_sanitizer" configuration from config/packages/framework.yaml. */
final class HtmlContentSanitizerTest extends IntegrationTestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function attacks(): iterable
    {
        yield 'script tag' => ['<p>कथा</p><script>alert(1)</script>', ['<script', 'alert(1)']];
        yield 'event handler' => ['<p onclick="alert(1)" onmouseover="x()">कथा</p>', ['onclick', 'onmouseover']];
        yield 'javascript url' => ['<a href="javascript:alert(1)">x</a>', ['javascript:']];
        yield 'data url' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', ['data:']];
        yield 'img onerror' => ['<img src=x onerror=alert(1)>', ['<img', 'onerror']];
        yield 'iframe' => ['<iframe src="https://evil.example"></iframe>', ['<iframe', 'evil.example']];
        yield 'inline style' => ['<p style="background:url(javascript:alert(1))">x</p>', ['style=', 'javascript']];
        yield 'svg payload' => ['<svg><script>alert(1)</script></svg>', ['<svg', '<script']];
        yield 'form phishing' => ['<form action="https://evil.example"><input name="pin"></form>', ['<form', '<input']];
        yield 'mixed case' => ['<ScRiPt>alert(1)</sCrIpT>', ['alert(1)']];
    }

    /** @param list<string> $forbidden */
    #[DataProvider('attacks')]
    public function testRemovesDangerousMarkup(string $html, array $forbidden): void
    {
        $clean = $this->service(HtmlContentSanitizer::class)->sanitize($html);

        foreach ($forbidden as $needle) {
            self::assertStringNotContainsStringIgnoringCase($needle, $clean);
        }
    }

    public function testKeepsEditorialMarkupAndHardensLinks(): void
    {
        $html = '<h2>शीर्षक</h2><p>पहला <strong>अनुच्छेद</strong> <em>भाव</em></p><blockquote>श्लोक</blockquote><ul><li>एक</li></ul><p><a href="https://example.com/x" target="_blank">स्रोत</a></p>';
        $clean = $this->service(HtmlContentSanitizer::class)->sanitize($html);

        self::assertStringContainsString('<h2>शीर्षक</h2>', $clean);
        self::assertStringContainsString('<strong>अनुच्छेद</strong>', $clean);
        self::assertStringContainsString('<blockquote>श्लोक</blockquote>', $clean);
        self::assertStringContainsString('<li>एक</li>', $clean);
        self::assertStringContainsString('href="https://example.com/x"', $clean);
        self::assertStringContainsString('rel="noopener noreferrer nofollow"', $clean);
        self::assertStringNotContainsString('target=', $clean);
    }

    public function testUnwrapsPresentationalTagsAndDropsEmptyParagraphs(): void
    {
        $clean = $this->service(HtmlContentSanitizer::class)->sanitize("<div><span style=\"color:red\">राम</span></div><p>\u{00A0}</p><p> </p>");

        self::assertSame('राम', $clean);
    }

    public function testPlainTextConversion(): void
    {
        $text = $this->service(HtmlContentSanitizer::class)->toPlainText('<p>पहला &amp; दूसरा</p><p>तीसरा<br>चौथा</p>');

        self::assertSame("पहला & दूसरा\n\nतीसरा\nचौथा", $text);
    }
}
