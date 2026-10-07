<?php

declare(strict_types=1);

namespace App\Service\Audio\Tts;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Pluggable text-to-speech engine. Implementations must write an audio file for the given text
 * or throw a TtsException with an admin-readable message. No implementation may silently succeed.
 */
#[AutoconfigureTag('app.tts_provider')]
interface TextToSpeechProviderInterface
{
    /** Identifier used by TTS_PROVIDER, e.g. "local" or "http". */
    public function getName(): string;

    public function isConfigured(): bool;

    /** Human-readable reason why the provider cannot be used, or null when it is ready. */
    public function getConfigurationError(): ?string;

    /** File extension of synthesized chunks ("wav" or "mp3"). */
    public function getOutputExtension(): string;

    /** Default voice when neither the story nor the settings specify one. */
    public function getDefaultVoice(): string;

    /**
     * @throws TtsException
     */
    public function synthesize(string $text, string $outputPath, TtsOptions $options): void;
}
