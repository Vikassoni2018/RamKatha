<?php

declare(strict_types=1);

namespace App\Service\Media;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Strict image validation: the real MIME type is sniffed from the file contents (not the client
 * header or extension), the file must decode as an image of sane dimensions, and SVG / scripts /
 * polyglots are rejected. Only JPEG, PNG and WebP are accepted.
 */
final class MediaUploadValidator
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MIN_DIMENSION = 50;
    public const MAX_DIMENSION = 8000;

    /** sniffed MIME => canonical extension */
    public const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const DANGEROUS_EXTENSIONS = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht', 'shtml', 'html', 'htm', 'js', 'svg', 'svgz', 'exe', 'sh', 'bat', 'cgi', 'pl', 'py', 'htaccess', 'asp', 'aspx', 'jsp'];

    /**
     * @return array{mime: string, extension: string, width: int, height: int}
     *
     * @throws MediaValidationException
     */
    public function validate(File $file): array
    {
        if ($file instanceof UploadedFile && !$file->isValid()) {
            throw new MediaValidationException('Upload failed: '.$file->getErrorMessage());
        }
        $size = $file->getSize();
        if (false === $size || $size <= 0) {
            throw new MediaValidationException('The file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new MediaValidationException(\sprintf('The file is too large (max %d MB).', self::MAX_BYTES / 1024 / 1024));
        }

        // Reject dangerous client-side names outright (double extensions like "x.php.jpg" too).
        if ($file instanceof UploadedFile) {
            $parts = explode('.', strtolower($file->getClientOriginalName()));
            array_shift($parts);
            if ([] !== array_intersect($parts, self::DANGEROUS_EXTENSIONS)) {
                throw new MediaValidationException('This file type is not allowed.');
            }
        }

        $mime = $file->getMimeType(); // content sniffing (fileinfo)
        if (null === $mime || !isset(self::ALLOWED[$mime])) {
            throw new MediaValidationException('Only JPEG, PNG and WebP images are allowed.');
        }

        $info = @getimagesize($file->getPathname());
        if (false === $info || ($info['mime'] ?? null) !== $mime) {
            throw new MediaValidationException('The file is not a valid image.');
        }
        [$width, $height] = $info;
        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new MediaValidationException(\sprintf('Image dimensions must be between %d and %d pixels.', self::MIN_DIMENSION, self::MAX_DIMENSION));
        }

        // Polyglot guard: refuse files that embed script tags / PHP openers anywhere.
        $handle = fopen($file->getPathname(), 'rb');
        if (false !== $handle) {
            while (!feof($handle)) {
                $chunk = (string) fread($handle, 65536);
                // Only long, specific markers: short ones (e.g. "<?=") occur by chance in binary data.
                if (false !== stripos($chunk, '<?php') || false !== stripos($chunk, '<script')) {
                    fclose($handle);
                    throw new MediaValidationException('The image contains embedded code and was rejected.');
                }
            }
            fclose($handle);
        }

        return ['mime' => $mime, 'extension' => self::ALLOWED[$mime], 'width' => (int) $width, 'height' => (int) $height];
    }
}
