<?php

declare(strict_types=1);

namespace App\Service\System;

use App\Repository\SystemHeartbeatRepository;
use App\Service\Audio\FfmpegService;
use App\Service\Audio\Tts\TtsProviderRegistry;
use App\Service\Config\FeatureFlags;
use App\Service\Donation\UpiConfiguration;
use App\Service\Social\FacebookConfig;
use App\Service\Storage\StorageInterface;
use App\Service\Storage\TempDirectoryManager;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Health checks for the System Status page and `app:system:health`. Never exposes secret values,
 * only whether something is configured/working.
 */
final class SystemHealthService
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const ERROR = 'error';

    /** Jobs expected to run regularly (name => max expected interval in minutes). */
    public const EXPECTED_JOBS = [
        'publish-scheduled' => 10,
        'social-due' => 15,
        'audio-retry' => 30,
        'analytics' => 120,
        'maintenance' => 1500,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly FfmpegService $ffmpeg,
        private readonly TtsProviderRegistry $tts,
        private readonly FacebookConfig $facebook,
        private readonly UpiConfiguration $upi,
        private readonly FeatureFlags $features,
        private readonly FailedMessageService $failedMessages,
        private readonly SystemHeartbeatRepository $heartbeats,
        #[Autowire(service: 'app.storage.public')]
        private readonly StorageInterface $publicStorage,
        #[Autowire(service: 'app.storage.private')]
        private readonly StorageInterface $privateStorage,
        private readonly TempDirectoryManager $temp,
        #[Autowire(param: 'kernel.logs_dir')]
        private readonly string $logsDir,
        #[Autowire(param: 'kernel.cache_dir')]
        private readonly string $cacheDir,
    ) {
    }

    /**
     * @return array<string, list<array{label: string, status: string, value: string, help: ?string}>>
     */
    public function checks(): array
    {
        return [
            'Platform' => $this->platformChecks(),
            'Storage' => $this->storageChecks(),
            'Audio' => $this->audioChecks(),
            'Background jobs' => $this->jobChecks(),
            'Integrations' => $this->integrationChecks(),
        ];
    }

    public function overallStatus(): string
    {
        $status = self::OK;
        foreach ($this->checks() as $group) {
            foreach ($group as $check) {
                if (self::ERROR === $check['status']) {
                    return self::ERROR;
                }
                if (self::WARN === $check['status']) {
                    $status = self::WARN;
                }
            }
        }

        return $status;
    }

    private function platformChecks(): array
    {
        $checks = [];
        $checks[] = $this->check('PHP version', \PHP_VERSION_ID >= 80300 ? self::OK : (\PHP_VERSION_ID >= 80200 ? self::WARN : self::ERROR), \PHP_VERSION, \PHP_VERSION_ID < 80300 ? 'PHP 8.3+ is recommended for production.' : null);
        $checks[] = $this->check('Symfony version', self::OK, Kernel::VERSION);

        try {
            $version = (string) $this->connection->fetchOne('SELECT VERSION()');
            $checks[] = $this->check('Database connection', self::OK, $version);
        } catch (\Throwable $e) {
            $checks[] = $this->check('Database connection', self::ERROR, 'unreachable', 'Check DATABASE_URL.');
        }

        foreach (['intl', 'mbstring', 'pdo_mysql', 'gd', 'fileinfo', 'xml'] as $ext) {
            $loaded = \extension_loaded($ext);
            $checks[] = $this->check('PHP extension: '.$ext, $loaded ? self::OK : ('gd' === $ext ? self::WARN : self::ERROR), $loaded ? 'loaded' : 'missing', 'gd' === $ext && !$loaded ? 'Without GD, uploaded images are stored without resized variants.' : null);
        }
        $checks[] = $this->check('Upload limits', self::OK, \sprintf('upload_max_filesize=%s, post_max_size=%s', \ini_get('upload_max_filesize'), \ini_get('post_max_size')));
        $checks[] = $this->check('Memory limit', self::OK, (string) \ini_get('memory_limit'));
        $checks[] = $this->check('OPcache', \function_exists('opcache_get_status') && @opcache_get_status(false) ? self::OK : self::WARN, \function_exists('opcache_get_status') && @opcache_get_status(false) ? 'enabled' : 'disabled', 'Enable OPcache in production.');

        return $checks;
    }

    private function storageChecks(): array
    {
        $checks = [];
        $dirs = [
            'Public media (public/media)' => $this->publicStorage->rootDirectory(),
            'Private storage (audio)' => $this->privateStorage->rootDirectory(),
            'Temp directory' => $this->temp->baseDirectory(),
            'Log directory' => $this->logsDir,
            'Cache directory' => $this->cacheDir,
        ];
        foreach ($dirs as $label => $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $writable = is_dir($dir) && is_writable($dir);
            $checks[] = $this->check($label, $writable ? self::OK : self::ERROR, $writable ? 'writable' : 'NOT writable', $writable ? null : 'Grant the web server user write access (see README → Permissions).');
        }
        $free = @disk_free_space($this->publicStorage->rootDirectory());
        if (false !== $free) {
            $gb = $free / 1024 / 1024 / 1024;
            $checks[] = $this->check('Free disk space', $gb < 1 ? self::ERROR : ($gb < 5 ? self::WARN : self::OK), \sprintf('%.1f GB', $gb), $gb < 5 ? 'Audio files need disk space; plan backups and cleanup.' : null);
        }

        return $checks;
    }

    private function audioChecks(): array
    {
        $provider = $this->tts->getActive();
        $checks = [];
        $checks[] = $this->check('TTS provider', $provider->isConfigured() ? self::OK : self::WARN, $provider->getName(), $provider->getConfigurationError());
        $ffmpeg = $this->ffmpeg->ffmpegVersion();
        $checks[] = $this->check('FFmpeg', null !== $ffmpeg ? self::OK : self::WARN, $ffmpeg ?? 'not available', null === $ffmpeg ? 'Required for audio generation and audiograms (FFMPEG_BINARY).' : null);
        $ffprobe = $this->ffmpeg->ffprobeVersion();
        $checks[] = $this->check('FFprobe', null !== $ffprobe ? self::OK : self::WARN, $ffprobe ?? 'not available', null === $ffprobe ? 'Used to validate audio and measure duration (FFPROBE_BINARY).' : null);
        $checks[] = $this->check('Audiograms', $this->features->audiogramAvailable() ? self::OK : self::WARN, $this->features->audiogramAvailable() ? 'available' : 'unavailable', $this->features->audiogramUnavailableReason());

        return $checks;
    }

    private function jobChecks(): array
    {
        $checks = [];
        try {
            $failed = $this->failedMessages->count();
            $checks[] = $this->check('Failed messages', 0 === (int) $failed ? self::OK : self::WARN, (string) ($failed ?? 'n/a'), $failed ? 'See Admin → System → Failed jobs.' : null);
            $pending = $this->failedMessages->pendingCount();
            $checks[] = $this->check('Queued messages', (int) $pending > 50 ? self::WARN : self::OK, (string) ($pending ?? 'n/a'), (int) $pending > 50 ? 'Is a worker or the cron fallback running?' : null);
        } catch (\Throwable) {
            $checks[] = $this->check('Messenger queue', self::ERROR, 'unavailable', 'Run the migrations (messenger_messages table).');
        }

        $heartbeats = $this->heartbeats->findAllIndexed();
        $now = new \DateTimeImmutable();
        foreach (self::EXPECTED_JOBS as $name => $maxMinutes) {
            $hb = $heartbeats[$name] ?? null;
            if (null === $hb) {
                $checks[] = $this->check('Job: '.$name, self::WARN, 'never run', 'Configure cron (README → Cron) or run the scheduler worker.');
                continue;
            }
            $age = (int) (($now->getTimestamp() - $hb->getLastRunAt()->getTimestamp()) / 60);
            $status = 'error' === $hb->getLastStatus() ? self::ERROR : ($age > $maxMinutes * 2 ? self::WARN : self::OK);
            $checks[] = $this->check('Job: '.$name, $status, \sprintf('%s, %d min ago (%s)', $hb->getLastStatus(), $age, $hb->getLastRunAt()->format('Y-m-d H:i').' UTC'), 'error' === $hb->getLastStatus() ? $hb->getLastMessage() : ($age > $maxMinutes * 2 ? 'Cron does not seem to run regularly.' : null));
        }

        return $checks;
    }

    private function integrationChecks(): array
    {
        return [
            $this->check('Facebook', $this->facebook->isConfigured() ? self::OK : self::WARN, $this->facebook->isConfigured() ? 'configured (page '.$this->facebook->getPageId().', token present)' : 'not configured', $this->facebook->getConfigurationError()),
            $this->check('UPI', $this->upi->isConfigured() ? self::OK : self::WARN, $this->upi->isConfigured() ? 'configured' : 'not configured', $this->upi->getConfigurationError()),
            $this->check('Mailer', $this->features->mailerConfigured() ? self::OK : self::WARN, $this->features->mailerConfigured() ? 'configured' : 'not configured (null transport)', $this->features->mailerConfigured() ? null : 'Email verification, password reset and notifications need MAILER_DSN.'),
        ];
    }

    /** @return array{label: string, status: string, value: string, help: ?string} */
    private function check(string $label, string $status, string $value, ?string $help = null): array
    {
        return ['label' => $label, 'status' => $status, 'value' => $value, 'help' => $help];
    }
}
