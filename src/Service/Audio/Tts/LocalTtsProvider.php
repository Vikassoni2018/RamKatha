<?php

declare(strict_types=1);

namespace App\Service\Audio\Tts;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a locally installed command-line TTS engine (piper, espeak-ng, ...) via Symfony Process.
 * No shell is involved: the binary and every argument are passed as an array, so text and voice
 * values can never be interpreted as shell syntax.
 *
 * Argument template placeholders: {input} {output} {voice} {language}
 * If the template does not contain {input}, the text is written to the engine's standard input.
 */
final class LocalTtsProvider implements TextToSpeechProviderInterface
{
    public function __construct(
        #[Autowire(env: 'TTS_BINARY_PATH')]
        private readonly string $binaryPath,
        #[Autowire(env: 'TTS_BINARY_ARGS')]
        private readonly string $argsTemplate,
        #[Autowire(env: 'TTS_VOICE')]
        private readonly string $defaultVoice,
        #[Autowire(env: 'TTS_OUTPUT_FORMAT')]
        private readonly string $outputFormat,
        #[Autowire(env: 'int:TTS_TIMEOUT')]
        private readonly int $timeout,
    ) {
    }

    public function getName(): string
    {
        return 'local';
    }

    public function isConfigured(): bool
    {
        return null === $this->getConfigurationError();
    }

    public function getConfigurationError(): ?string
    {
        if ('' === trim($this->binaryPath)) {
            return 'Local TTS is not configured: set TTS_BINARY_PATH to the path of a command-line TTS engine.';
        }
        if (!is_file($this->binaryPath)) {
            return \sprintf('Local TTS engine not found at "%s" (TTS_BINARY_PATH).', $this->binaryPath);
        }
        if ('\\' !== \DIRECTORY_SEPARATOR && !is_executable($this->binaryPath)) {
            return \sprintf('Local TTS engine at "%s" is not executable.', $this->binaryPath);
        }
        if (!\in_array($this->getOutputExtension(), ['wav', 'mp3'], true)) {
            return 'TTS_OUTPUT_FORMAT must be "wav" or "mp3".';
        }
        if (!str_contains($this->argsTemplate, '{output}')) {
            return 'TTS_BINARY_ARGS must contain the {output} placeholder.';
        }

        return null;
    }

    public function getOutputExtension(): string
    {
        return strtolower(trim($this->outputFormat)) ?: 'wav';
    }

    public function getDefaultVoice(): string
    {
        return $this->defaultVoice;
    }

    public function synthesize(string $text, string $outputPath, TtsOptions $options): void
    {
        if (null !== $error = $this->getConfigurationError()) {
            throw new TtsException($error);
        }
        if (str_starts_with($options->voice, '-')) {
            throw new TtsException('Invalid voice name.');
        }

        $useStdin = !str_contains($this->argsTemplate, '{input}');
        $inputPath = $outputPath.'.txt';
        if (!$useStdin) {
            file_put_contents($inputPath, $text);
        }

        $replacements = [
            '{input}' => $inputPath,
            '{output}' => $outputPath,
            '{voice}' => $options->voice,
            '{language}' => $options->language,
        ];
        $args = [$this->binaryPath];
        foreach (preg_split('/\s+/', trim($this->argsTemplate), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $args[] = strtr($token, $replacements);
        }

        $process = new Process($args, \dirname($outputPath), null, $useStdin ? $text : null, $this->timeout);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new TtsException(\sprintf('Local TTS engine timed out after %d seconds.', $this->timeout));
        } finally {
            if (!$useStdin && is_file($inputPath)) {
                @unlink($inputPath);
            }
        }

        if (!$process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            throw new TtsException(\sprintf('Local TTS engine failed (exit code %s): %s', $process->getExitCode() ?? '?', mb_substr($stderr, 0, 500)));
        }
        if (!is_file($outputPath) || filesize($outputPath) < 100) {
            throw new TtsException('Local TTS engine finished but produced no audio file. Check TTS_BINARY_ARGS.');
        }
    }
}
