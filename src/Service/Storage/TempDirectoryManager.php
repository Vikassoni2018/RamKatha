<?php

declare(strict_types=1);

namespace App\Service\Storage;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/**
 * Creates and cleans job-scoped working directories under var/storage/temp
 * (audio chunks, audiogram frames, uploads being processed).
 */
final class TempDirectoryManager
{
    private readonly Filesystem $filesystem;

    public function __construct(private readonly string $baseDir)
    {
        $this->filesystem = new Filesystem();
    }

    /** Creates a fresh unique directory, e.g. var/storage/temp/audio/0192.... */
    public function create(string $purpose): string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $purpose)) {
            throw new \InvalidArgumentException('Invalid temp directory purpose.');
        }
        $dir = rtrim($this->baseDir, '/\\').'/'.$purpose.'/'.Uuid::v7()->toBase58();
        $this->filesystem->mkdir($dir, 0775);

        return $dir;
    }

    public function remove(string $dir): void
    {
        // Only ever delete inside our own base directory.
        $real = realpath($dir);
        $base = realpath($this->baseDir);
        if (false === $real || false === $base || !str_starts_with($real, $base.\DIRECTORY_SEPARATOR)) {
            return;
        }
        $this->filesystem->remove($real);
    }

    /** Removes job directories older than the given age. Returns number of removed directories. */
    public function purgeOlderThan(int $seconds): int
    {
        if (!is_dir($this->baseDir)) {
            return 0;
        }
        $removed = 0;
        $threshold = time() - $seconds;
        foreach (new \DirectoryIterator($this->baseDir) as $purposeDir) {
            if ($purposeDir->isDot() || !$purposeDir->isDir()) {
                continue;
            }
            foreach (new \DirectoryIterator($purposeDir->getPathname()) as $jobDir) {
                if ($jobDir->isDot() || !$jobDir->isDir()) {
                    continue;
                }
                if ($jobDir->getMTime() < $threshold) {
                    $this->filesystem->remove($jobDir->getPathname());
                    ++$removed;
                }
            }
        }

        return $removed;
    }

    public function baseDirectory(): string
    {
        return $this->baseDir;
    }
}
