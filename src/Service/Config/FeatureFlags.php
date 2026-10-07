<?php

declare(strict_types=1);

namespace App\Service\Config;

use App\Enum\SocialPlatform;
use App\Repository\SocialAccountRepository;
use App\Service\Audio\FfmpegService;
use App\Service\Audio\Tts\TtsProviderRegistry;
use App\Service\Donation\UpiConfiguration;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use App\Service\Social\FacebookConfig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Normalised view of which optional features are usable right now, combining ENV configuration,
 * admin settings and installed binaries. Templates and controllers ask this service instead of
 * reading environment variables directly.
 */
final class FeatureFlags
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TtsProviderRegistry $tts,
        private readonly FfmpegService $ffmpeg,
        private readonly FacebookConfig $facebook,
        private readonly SocialAccountRepository $socialAccounts,
        private readonly UpiConfiguration $upi,
        #[Autowire(env: 'bool:AUDIOGRAM_ENABLED')]
        private readonly bool $audiogramEnabled,
        #[Autowire(env: 'MAILER_DSN')]
        #[\SensitiveParameter]
        private readonly string $mailerDsn,
    ) {
    }

    public function ttsConfigured(): bool
    {
        return $this->tts->getActive()->isConfigured();
    }

    /** TTS + FFmpeg are both required to produce a final MP3. */
    public function audioGenerationAvailable(): bool
    {
        return null === $this->audioGenerationUnavailableReason();
    }

    public function audioGenerationUnavailableReason(): ?string
    {
        $provider = $this->tts->getActive();
        if (!$provider->isConfigured()) {
            return $provider->getConfigurationError();
        }
        if (!$this->ffmpeg->isFfmpegAvailable()) {
            return 'FFmpeg is not available. It is required to merge and encode narration (set FFMPEG_BINARY).';
        }

        return null;
    }

    public function facebookConfigured(): bool
    {
        return $this->facebook->isConfigured();
    }

    /** Configured in ENV and switched on by an admin. */
    public function facebookEnabled(): bool
    {
        if (!$this->facebook->isConfigured()) {
            return false;
        }
        try {
            return (bool) $this->socialAccounts->findOneBy(['platform' => SocialPlatform::Facebook])?->isEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    public function audiogramAvailable(): bool
    {
        return $this->audiogramEnabled && $this->ffmpeg->isFfmpegAvailable();
    }

    public function audiogramUnavailableReason(): ?string
    {
        if (!$this->audiogramEnabled) {
            return 'Audiograms are disabled (AUDIOGRAM_ENABLED=0).';
        }
        if (!$this->ffmpeg->isFfmpegAvailable()) {
            return 'FFmpeg is not available; audiograms cannot be generated.';
        }

        return null;
    }

    public function donationsEnabled(): bool
    {
        return $this->settings->getBool(SettingsRegistry::DONATIONS_ENABLED) && $this->upi->isConfigured();
    }

    public function registrationEnabled(): bool
    {
        return $this->settings->getBool(SettingsRegistry::REGISTRATION_ENABLED);
    }

    public function publicCollectionsEnabled(): bool
    {
        return $this->settings->getBool(SettingsRegistry::PUBLIC_COLLECTIONS_ENABLED);
    }

    public function analyticsEnabled(): bool
    {
        return $this->settings->getBool(SettingsRegistry::ANALYTICS_ENABLED);
    }

    public function mailerConfigured(): bool
    {
        $dsn = trim($this->mailerDsn);

        return '' !== $dsn && !str_starts_with($dsn, 'null://');
    }
}
