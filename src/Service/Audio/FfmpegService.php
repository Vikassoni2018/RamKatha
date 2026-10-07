<?php

declare(strict_types=1);

namespace App\Service\Audio;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Thin wrapper around the FFmpeg / FFprobe binaries (executed through Symfony Process, no shell).
 * Availability checks are cached for a few minutes so pages can show feature status cheaply.
 */
final class FfmpegService
{
    public function __construct(
        #[Autowire(env: 'FFMPEG_BINARY')]
        private readonly string $ffmpegBinary,
        #[Autowire(env: 'FFPROBE_BINARY')]
        private readonly string $ffprobeBinary,
        #[Autowire(env: 'AUDIO_BITRATE')]
        private readonly string $bitrate,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
        #[Autowire(service: 'monolog.logger.audio')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isFfmpegAvailable(): bool
    {
        return null !== $this->ffmpegVersion();
    }

    public function isFfprobeAvailable(): bool
    {
        return null !== $this->ffprobeVersion();
    }

    public function ffmpegVersion(): ?string
    {
        return $this->detectVersion($this->ffmpegBinary, 'ffmpeg');
    }

    public function ffprobeVersion(): ?string
    {
        return $this->detectVersion($this->ffprobeBinary, 'ffprobe');
    }

    public function resolvedFfmpegPath(): ?string
    {
        return $this->resolve($this->ffmpegBinary);
    }

    public function resolvedFfprobePath(): ?string
    {
        return $this->resolve($this->ffprobeBinary);
    }

    /** Clears the cached availability (after changing configuration). */
    public function resetDetection(): void
    {
        $this->cache->delete('ffmpeg_version_'.md5($this->ffmpegBinary));
        $this->cache->delete('ffmpeg_version_'.md5($this->ffprobeBinary));
    }

    /** Duration in seconds, or null if it cannot be determined. */
    public function probeDuration(string $file): ?float
    {
        if (!$this->isFfprobeAvailable() || !is_file($file)) {
            return null;
        }
        $process = new Process([$this->ffprobeBinary, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $file], null, null, null, 60);
        $process->run();
        if (!$process->isSuccessful()) {
            return null;
        }
        $value = trim($process->getOutput());

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Concatenates audio chunks, normalises loudness (EBU R128) and encodes a mono MP3.
     *
     * @param list<string> $inputs absolute paths in playback order
     */
    public function concatenateToMp3(array $inputs, string $output, bool $normalize = true): void
    {
        if (!$this->isFfmpegAvailable()) {
            throw new AudioGenerationException(\sprintf('FFmpeg is not available (FFMPEG_BINARY="%s"). Install FFmpeg or fix the path.', $this->ffmpegBinary));
        }
        if ([] === $inputs) {
            throw new AudioGenerationException('No audio chunks to merge.');
        }

        $listFile = \dirname($output).'/concat.txt';
        $lines = array_map(
            // The concat demuxer needs single quotes escaped as '\''.
            static fn (string $path) => "file '".str_replace("'", "'\\''", str_replace('\\', '/', $path))."'",
            $inputs,
        );
        file_put_contents($listFile, implode("\n", $lines)."\n");

        $args = [$this->ffmpegBinary, '-y', '-hide_banner', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', $listFile];
        if ($normalize) {
            $args[] = '-af';
            $args[] = 'loudnorm=I=-16:TP=-1.5:LRA=11';
        }
        array_push($args, '-ac', '1', '-ar', '44100', '-c:a', 'libmp3lame', '-b:a', $this->sanitizeBitrate(), $output);

        $process = new Process($args, \dirname($output), null, null, 1800);
        $process->run();
        @unlink($listFile);

        if (!$process->isSuccessful() || !is_file($output) || filesize($output) < 1000) {
            $error = trim($process->getErrorOutput()) ?: 'unknown error';
            $this->logger->error('FFmpeg merge failed: {error}', ['error' => $error]);
            throw new AudioGenerationException('FFmpeg could not merge/encode the audio: '.mb_substr($error, 0, 500));
        }
    }

    /**
     * Renders an audiogram: still image (cover) + title band + first N seconds of audio → MP4.
     */
    public function renderAudiogram(string $imagePath, string $audioPath, string $output, int $seconds = 45): void
    {
        if (!$this->isFfmpegAvailable()) {
            throw new AudioGenerationException('FFmpeg is not available; audiograms cannot be generated.');
        }
        $filter = 'scale=1080:1080:force_original_aspect_ratio=increase,crop=1080:1080,format=yuv420p';
        $args = [
            $this->ffmpegBinary, '-y', '-hide_banner', '-loglevel', 'error',
            '-loop', '1', '-i', $imagePath,
            '-i', $audioPath,
            '-t', (string) max(5, min(120, $seconds)),
            '-vf', $filter,
            '-c:v', 'libx264', '-tune', 'stillimage', '-preset', 'veryfast', '-r', '15',
            '-c:a', 'aac', '-b:a', '96k', '-shortest', '-movflags', '+faststart',
            $output,
        ];
        $process = new Process($args, \dirname($output), null, null, 900);
        $process->run();
        if (!$process->isSuccessful() || !is_file($output) || filesize($output) < 1000) {
            throw new AudioGenerationException('FFmpeg could not render the audiogram: '.mb_substr(trim($process->getErrorOutput()), 0, 500));
        }
    }

    private function sanitizeBitrate(): string
    {
        return preg_match('/^\d{2,3}k$/', $this->bitrate) ? $this->bitrate : '64k';
    }

    private function detectVersion(string $binary, string $label): ?string
    {
        if ('' === trim($binary)) {
            return null;
        }

        return $this->cache->get('ffmpeg_version_'.md5($binary), function (ItemInterface $item) use ($binary, $label): ?string {
            $item->expiresAfter(300);
            $path = $this->resolve($binary);
            if (null === $path) {
                return null;
            }
            try {
                $process = new Process([$path, '-version'], null, null, null, 10);
                $process->run();
                if (!$process->isSuccessful()) {
                    return null;
                }
                $firstLine = strtok($process->getOutput(), "\n") ?: $label;

                return trim($firstLine);
            } catch (\Throwable $e) {
                $this->logger->warning('{label} detection failed: {error}', ['label' => $label, 'error' => $e->getMessage()]);

                return null;
            }
        });
    }

    private function resolve(string $binary): ?string
    {
        if ('' === trim($binary)) {
            return null;
        }
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary) ? $binary : null;
        }

        return (new ExecutableFinder())->find($binary);
    }
}
