<?php

declare(strict_types=1);

namespace App\Service\Storage;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Local filesystem storage rooted at a fixed directory.
 * Every key is validated against a strict whitelist so untrusted input can never escape the root.
 */
final class LocalStorage implements StorageInterface
{
    private const KEY_PATTERN = '#^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)*$#';

    private readonly string $rootDir;
    private readonly Filesystem $filesystem;

    public function __construct(string $rootDir, private readonly ?string $publicUrlPrefix = null)
    {
        $this->rootDir = rtrim(str_replace('\\', '/', $rootDir), '/');
        $this->filesystem = new Filesystem();
    }

    public static function assertValidKey(string $key): void
    {
        if ('' === $key || \strlen($key) > 255 || str_contains($key, '..') || !preg_match(self::KEY_PATTERN, $key)) {
            throw new StorageException(\sprintf('Invalid storage key "%s".', mb_substr($key, 0, 80)));
        }
    }

    public function storeFile(string $sourcePath, string $key): void
    {
        $target = $this->localPath($key);
        try {
            $this->filesystem->mkdir(\dirname($target), 0775);
            $this->filesystem->copy($sourcePath, $target, true);
        } catch (IOExceptionInterface $e) {
            throw new StorageException('Could not store file: '.$e->getMessage(), previous: $e);
        }
    }

    public function write(string $key, string $contents): void
    {
        $target = $this->localPath($key);
        try {
            $this->filesystem->mkdir(\dirname($target), 0775);
            $this->filesystem->dumpFile($target, $contents);
        } catch (IOExceptionInterface $e) {
            throw new StorageException('Could not write file: '.$e->getMessage(), previous: $e);
        }
    }

    public function read(string $key): string
    {
        $path = $this->localPath($key);
        if (!is_file($path)) {
            throw new StorageException(\sprintf('File "%s" does not exist.', $key));
        }
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new StorageException(\sprintf('File "%s" could not be read.', $key));
        }

        return $contents;
    }

    public function exists(string $key): bool
    {
        return is_file($this->localPath($key));
    }

    public function delete(string $key): void
    {
        $path = $this->localPath($key);
        if (is_file($path)) {
            try {
                $this->filesystem->remove($path);
            } catch (IOExceptionInterface $e) {
                throw new StorageException('Could not delete file: '.$e->getMessage(), previous: $e);
            }
        }
    }

    public function size(string $key): int
    {
        $size = @filesize($this->localPath($key));

        return false === $size ? 0 : $size;
    }

    public function localPath(string $key): string
    {
        self::assertValidKey($key);

        return $this->rootDir.'/'.$key;
    }

    public function publicUrl(string $key): ?string
    {
        if (null === $this->publicUrlPrefix) {
            return null;
        }
        self::assertValidKey($key);

        return rtrim($this->publicUrlPrefix, '/').'/'.$key;
    }

    public function isPublic(): bool
    {
        return null !== $this->publicUrlPrefix;
    }

    public function listKeys(string $prefix = ''): iterable
    {
        $base = '' === $prefix ? $this->rootDir : $this->localPath($prefix);
        if (!is_dir($base)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                yield ltrim(substr(str_replace('\\', '/', $file->getPathname()), \strlen($this->rootDir)), '/');
            }
        }
    }

    public function rootDirectory(): string
    {
        return $this->rootDir;
    }
}
