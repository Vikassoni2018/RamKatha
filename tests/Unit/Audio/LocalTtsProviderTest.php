<?php

declare(strict_types=1);

namespace App\Tests\Unit\Audio;

use App\Service\Audio\Tts\LocalTtsProvider;
use App\Service\Audio\Tts\TtsException;
use App\Service\Audio\Tts\TtsOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Uses the PHP binary running the tests as a stand-in "TTS engine", so the real Symfony Process
 * integration (argument passing without a shell, stdin mode, error reporting) is exercised.
 */
final class LocalTtsProviderTest extends TestCase
{
    private string $dir;
    private string $engine;

    protected function setUp(): void
    {
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()).'/katha-tts-'.bin2hex(random_bytes(4));
        if (preg_match('/\s/', $this->dir)) {
            self::markTestSkipped('The temp directory path contains whitespace.');
        }
        mkdir($this->dir);
        $this->engine = $this->dir.'/fake-engine.php';
        file_put_contents($this->engine, <<<'PHP'
            <?php
            $opts = [];
            for ($i = 1; $i < $argc; $i += 2) { $opts[$argv[$i]] = $argv[$i + 1] ?? ''; }
            $text = isset($opts['--in']) ? file_get_contents($opts['--in']) : stream_get_contents(STDIN);
            if (str_contains($text, 'FAIL')) { fwrite(STDERR, 'engine exploded'); exit(3); }
            if (str_contains($text, 'EMPTY')) { exit(0); }
            file_put_contents($opts['--out'], str_repeat('A', 200).json_encode(['text' => $text, 'voice' => $opts['--voice'] ?? null, 'argc' => $argc], JSON_UNESCAPED_UNICODE));
            PHP);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    private function provider(string $args, string $binary = \PHP_BINARY, string $format = 'wav'): LocalTtsProvider
    {
        return new LocalTtsProvider($binary, $args, 'hi-voice', $format, 30);
    }

    /** @return array{text: string, voice: ?string, argc: int} */
    private function engineResult(string $file): array
    {
        return json_decode(substr((string) file_get_contents($file), 200), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testReportsMissingConfiguration(): void
    {
        self::assertStringContainsString('TTS_BINARY_PATH', (string) $this->provider('{output}', '')->getConfigurationError());
        self::assertStringContainsString('not found', (string) $this->provider('{output}', $this->dir.'/missing-engine')->getConfigurationError());
        self::assertStringContainsString('{output}', (string) $this->provider('--in {input}')->getConfigurationError());
        self::assertStringContainsString('TTS_OUTPUT_FORMAT', (string) $this->provider('{output}', \PHP_BINARY, 'ogg')->getConfigurationError());
        self::assertTrue($this->provider($this->engine.' --out {output}')->isConfigured());
    }

    public function testNotConfiguredProviderRefusesToSynthesize(): void
    {
        $this->expectException(TtsException::class);
        $this->provider('{output}', '')->synthesize('नमस्ते', $this->dir.'/x.wav', new TtsOptions('v', 'hi-IN'));
    }

    public function testPassesTextAsFileWithoutShellInterpretation(): void
    {
        $out = $this->dir.'/chunk.wav';
        $text = 'राम "नाम" सत्य है; $(whoami) `id` && echo pwned | rm -rf /';

        $this->provider($this->engine.' --in {input} --out {output} --voice {voice}')->synthesize($text, $out, new TtsOptions('hi_IN-test', 'hi-IN'));

        $result = $this->engineResult($out);
        self::assertSame($text, $result['text'], 'Text must reach the engine byte-for-byte.');
        self::assertSame('hi_IN-test', $result['voice']);
        self::assertSame(7, $result['argc'], 'Every template token is exactly one argument.');
        self::assertFileDoesNotExist($out.'.txt', 'The temporary input file is removed.');
    }

    public function testUsesStandardInputWhenTemplateHasNoInputPlaceholder(): void
    {
        $out = $this->dir.'/chunk.wav';
        $this->provider($this->engine.' --out {output}')->synthesize('ॐ नमः शिवाय', $out, new TtsOptions('v', 'hi-IN'));

        self::assertSame('ॐ नमः शिवाय', $this->engineResult($out)['text']);
    }

    public function testRejectsVoiceThatLooksLikeAnOption(): void
    {
        $this->expectException(TtsException::class);
        $this->expectExceptionMessage('Invalid voice');
        $this->provider($this->engine.' --out {output} --voice {voice}')->synthesize('x', $this->dir.'/c.wav', new TtsOptions('--help', 'hi-IN'));
    }

    public function testEngineFailureIsReportedWithStderr(): void
    {
        try {
            $this->provider($this->engine.' --out {output}')->synthesize('FAIL please', $this->dir.'/c.wav', new TtsOptions('v', 'hi-IN'));
            self::fail('Expected TtsException.');
        } catch (TtsException $e) {
            self::assertStringContainsString('exit code 3', $e->getMessage());
            self::assertStringContainsString('engine exploded', $e->getMessage());
        }
    }

    public function testMissingOutputIsAFailureNotASilentSuccess(): void
    {
        $this->expectException(TtsException::class);
        $this->expectExceptionMessage('produced no audio');
        $this->provider($this->engine.' --out {output}')->synthesize('EMPTY', $this->dir.'/c.wav', new TtsOptions('v', 'hi-IN'));
    }
}
