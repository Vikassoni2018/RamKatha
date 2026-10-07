<?php

declare(strict_types=1);

namespace App\Tests\Unit\Donation;

use App\Service\Donation\QrCodeService;
use App\Service\Storage\LocalStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class QrCodeServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/katha-qr-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testGeneratesSvgLocallyAndCachesBySha1(): void
    {
        $service = new QrCodeService(new LocalStorage($this->dir, '/media'));
        $uri = 'upi://pay?pa=demo%40upi&pn=Demo&am=101.00&cu=INR';

        $svg = $service->svg($uri);
        self::assertStringContainsString('<svg', $svg);
        self::assertStringNotContainsString('<script', $svg);

        $key = $service->storeSvg($uri);
        self::assertSame('qr/'.sha1($uri).'.svg', $key);
        self::assertFileExists($this->dir.'/'.$key);
        self::assertSame('/media/'.$key, $service->publicUrl($key));

        $mtime = filemtime($this->dir.'/'.$key);
        self::assertSame($key, $service->storeSvg($uri), 'Same payload must reuse the stored file.');
        self::assertSame($mtime, filemtime($this->dir.'/'.$key));
    }

    public function testGeneratesPngWhenGdIsAvailable(): void
    {
        if (!\extension_loaded('gd')) {
            self::markTestSkipped('GD not available.');
        }
        $png = (new QrCodeService(new LocalStorage($this->dir, null)))->png('upi://pay?pa=a@b');
        self::assertStringStartsWith("\x89PNG", $png);
    }
}
