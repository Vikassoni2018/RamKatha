<?php

declare(strict_types=1);

namespace App\Service\Audio\Tts;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Resolves the active TTS provider from TTS_PROVIDER (none | local | http | any custom name). */
final class TtsProviderRegistry
{
    /** @var array<string, TextToSpeechProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<TextToSpeechProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.tts_provider')]
        iterable $providers,
        #[Autowire(env: 'TTS_PROVIDER')]
        private readonly string $activeName,
        #[Autowire(env: 'TTS_LANGUAGE')]
        private readonly string $language,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->getName()] = $provider;
        }
    }

    public function getActive(): TextToSpeechProviderInterface
    {
        return $this->providers[strtolower(trim($this->activeName))] ?? $this->providers['none'] ?? new NullTtsProvider();
    }

    public function getActiveName(): string
    {
        return $this->getActive()->getName();
    }

    /** BCP-47 language code passed to the engine (e.g. hi-IN). */
    public function getLanguage(): string
    {
        return $this->language;
    }

    /** @return array<string, TextToSpeechProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }
}
