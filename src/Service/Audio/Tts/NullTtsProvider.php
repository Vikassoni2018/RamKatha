<?php

declare(strict_types=1);

namespace App\Service\Audio\Tts;

/** Active when TTS_PROVIDER=none: every operation reports "not configured" (never fakes audio). */
final class NullTtsProvider implements TextToSpeechProviderInterface
{
    public function getName(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function getConfigurationError(): ?string
    {
        return 'Text-to-speech is not configured (TTS_PROVIDER=none). Configure a local engine or an HTTP endpoint to generate audio.';
    }

    public function getOutputExtension(): string
    {
        return 'wav';
    }

    public function getDefaultVoice(): string
    {
        return '';
    }

    public function synthesize(string $text, string $outputPath, TtsOptions $options): void
    {
        throw new TtsException((string) $this->getConfigurationError());
    }
}
