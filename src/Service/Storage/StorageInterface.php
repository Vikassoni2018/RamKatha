<?php

declare(strict_types=1);

namespace App\Service\Storage;

/**
 * Storage abstraction. Keys are relative, forward-slash separated paths such as
 * "covers/2026/10/0192f3.jpg". Implementations MUST reject traversal ("..") and absolute keys.
 *
 * Only LocalStorage is implemented (no cloud dependency); another adapter can be added later
 * without changing callers.
 */
interface StorageInterface
{
    /** Copies a local file into storage under the given key. */
    public function storeFile(string $sourcePath, string $key): void;

    /** Writes raw contents under the given key. */
    public function write(string $key, string $contents): void;

    public function read(string $key): string;

    public function exists(string $key): bool;

    public function delete(string $key): void;

    public function size(string $key): int;

    /** Absolute local path (internal use only, never exposed to clients). */
    public function localPath(string $key): string;

    /** Public URL path for the key, or null when the disk is private. */
    public function publicUrl(string $key): ?string;

    public function isPublic(): bool;

    /**
     * Lists keys below a prefix (recursive).
     *
     * @return iterable<string>
     */
    public function listKeys(string $prefix = ''): iterable;

    public function rootDirectory(): string;
}
