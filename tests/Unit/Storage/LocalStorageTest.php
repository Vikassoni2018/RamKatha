<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Service\Storage\LocalStorage;
use App\Service\Storage\StorageException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LocalStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/katha-storage-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function maliciousKeys(): iterable
    {
        yield 'parent traversal' => ['../../etc/passwd'];
        yield 'nested traversal' => ['audio/../../.env'];
        yield 'absolute unix path' => ['/etc/passwd'];
        yield 'windows drive' => ['C:/Windows/win.ini'];
        yield 'backslash traversal' => ['audio\\..\\..\\.env'];
        yield 'hidden file' => ['.htaccess'];
        yield 'dot segment' => ['audio/./x.mp3'];
        yield 'empty segment' => ['audio//x.mp3'];
        yield 'null byte' => ["audio/x.mp3\0.jpg"];
        yield 'empty' => [''];
        yield 'spaces' => ['my file.jpg'];
        yield 'url encoded' => ['%2e%2e/secret'];
    }

    #[DataProvider('maliciousKeys')]
    public function testRejectsKeysThatCouldEscapeTheRoot(string $key): void
    {
        $storage = new LocalStorage($this->dir, '/media');

        $this->expectException(StorageException::class);
        $storage->write($key, 'x');
    }

    public function testRoundTripInsideRoot(): void
    {
        $storage = new LocalStorage($this->dir, '/media/');
        $storage->write('covers/2026/10/a-b_c.1.webp', 'image-bytes');

        self::assertTrue($storage->exists('covers/2026/10/a-b_c.1.webp'));
        self::assertSame('image-bytes', $storage->read('covers/2026/10/a-b_c.1.webp'));
        self::assertSame(11, $storage->size('covers/2026/10/a-b_c.1.webp'));
        self::assertSame('/media/covers/2026/10/a-b_c.1.webp', $storage->publicUrl('covers/2026/10/a-b_c.1.webp'));
        self::assertSame(['covers/2026/10/a-b_c.1.webp'], iterator_to_array($storage->listKeys('covers'), false));

        $storage->delete('covers/2026/10/a-b_c.1.webp');
        self::assertFalse($storage->exists('covers/2026/10/a-b_c.1.webp'));
    }

    public function testPrivateDiskHasNoPublicUrls(): void
    {
        $storage = new LocalStorage($this->dir);

        self::assertFalse($storage->isPublic());
        self::assertNull($storage->publicUrl('audio/x.mp3'));
    }

    public function testReadingMissingFileFails(): void
    {
        $this->expectException(StorageException::class);
        (new LocalStorage($this->dir))->read('audio/missing.mp3');
    }
}
