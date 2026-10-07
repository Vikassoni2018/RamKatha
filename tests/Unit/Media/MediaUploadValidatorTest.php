<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Service\Media\MediaUploadValidator;
use App\Service\Media\MediaValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MediaUploadValidatorTest extends TestCase
{
    private string $dir;
    private MediaUploadValidator $validator;

    protected function setUp(): void
    {
        if (!\extension_loaded('gd') || !\extension_loaded('fileinfo')) {
            self::markTestSkipped('GD and fileinfo are required.');
        }
        $this->dir = sys_get_temp_dir().'/katha-upload-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->validator = new MediaUploadValidator();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    private function jpeg(int $width = 120, int $height = 90): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    private function upload(string $contents, string $clientName): UploadedFile
    {
        $path = $this->dir.'/'.bin2hex(random_bytes(6));
        file_put_contents($path, $contents);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function assertRejected(UploadedFile $file, string $messagePart): void
    {
        try {
            $this->validator->validate($file);
            self::fail('The upload should have been rejected.');
        } catch (MediaValidationException $e) {
            self::assertStringContainsString($messagePart, $e->getMessage());
        }
    }

    public function testAcceptsRealJpegAndReportsSniffedType(): void
    {
        $result = $this->validator->validate($this->upload($this->jpeg(), 'cover.jpeg'));

        self::assertSame(['mime' => 'image/jpeg', 'extension' => 'jpg', 'width' => 120, 'height' => 90], $result);
    }

    public function testTypeComesFromContentNotFromTheName(): void
    {
        // A real JPEG uploaded with a .png name is still a JPEG.
        self::assertSame('jpg', $this->validator->validate($this->upload($this->jpeg(), 'photo.png'))['extension']);
    }

    public function testRejectsPhpDisguisedAsJpeg(): void
    {
        $this->assertRejected($this->upload("<?php system(\$_GET['c']); ?>", 'cover.jpg'), 'Only JPEG, PNG and WebP');
    }

    public function testRejectsSvg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><script>alert(1)</script></svg>';
        $this->assertRejected($this->upload($svg, 'logo.svg'), 'not allowed');
        $this->assertRejected($this->upload($svg, 'logo.png'), 'Only JPEG, PNG and WebP');
    }

    public function testRejectsPolyglotImageWithEmbeddedCode(): void
    {
        $this->assertRejected($this->upload($this->jpeg()."<?php echo 'owned'; ?>", 'cover.jpg'), 'embedded code');
        $this->assertRejected($this->upload($this->jpeg().'<script>alert(1)</script>', 'cover.jpg'), 'embedded code');
    }

    public function testRejectsDoubleExtensions(): void
    {
        $this->assertRejected($this->upload($this->jpeg(), 'shell.php.jpg'), 'not allowed');
        $this->assertRejected($this->upload($this->jpeg(), 'page.html.jpg'), 'not allowed');
    }

    public function testRejectsTinyAndEmptyImages(): void
    {
        $this->assertRejected($this->upload($this->jpeg(10, 10), 'tiny.jpg'), 'dimensions');
        $this->assertRejected($this->upload('', 'empty.jpg'), 'empty');
    }

    public function testRejectsTruncatedImage(): void
    {
        $this->assertRejected($this->upload(substr($this->jpeg(), 0, 40), 'broken.jpg'), 'not a valid image');
    }
}
