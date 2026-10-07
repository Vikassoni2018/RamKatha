<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\MediaFile;

/**
 * GD based image processing: re-encoding (strips metadata and any embedded payload) and resized
 * variants. Never upscales. When GD is unavailable, callers fall back to the validated original.
 */
final class ImageProcessor
{
    /** variant => max width */
    public const VARIANTS = [
        MediaFile::VARIANT_THUMB => 400,
        MediaFile::VARIANT_CARD => 800,
        MediaFile::VARIANT_LARGE => 1600,
    ];

    public function isAvailable(): bool
    {
        return \extension_loaded('gd') && \function_exists('imagecreatetruecolor');
    }

    public function supports(string $mime): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        return match ($mime) {
            'image/jpeg' => \function_exists('imagecreatefromjpeg'),
            'image/png' => \function_exists('imagecreatefrompng'),
            'image/webp' => \function_exists('imagecreatefromwebp') && \function_exists('imagewebp'),
            default => false,
        };
    }

    /**
     * Re-encodes the image in place-format, optionally downscaling to $maxWidth.
     *
     * @return array{width: int, height: int}
     */
    public function resize(string $source, string $target, string $mime, ?int $maxWidth = null): array
    {
        $image = $this->load($source, $mime);
        $width = imagesx($image);
        $height = imagesy($image);

        if (null !== $maxWidth && $width > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = (int) max(1, round($height * $maxWidth / $width));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            $this->preserveTransparency($resized, $mime);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
            $width = $newWidth;
            $height = $newHeight;
        }

        $this->save($image, $target, $mime);
        imagedestroy($image);

        return ['width' => $width, 'height' => $height];
    }

    /**
     * Square-crops and resizes to exactly $size×$size (used for audiogram frames).
     */
    public function squareCrop(string $source, string $target, string $mime, int $size): void
    {
        $image = $this->load($source, $mime);
        $w = imagesx($image);
        $h = imagesy($image);
        $side = min($w, $h);
        $square = imagecreatetruecolor($size, $size);
        imagecopyresampled($square, $image, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);

        // Subtle dark gradient band at the bottom for legibility of overlays.
        for ($y = (int) ($size * 0.62); $y < $size; ++$y) {
            $alpha = (int) max(0, 127 - (($y - $size * 0.62) / ($size * 0.38)) * 90);
            imageline($square, 0, $y, $size, $y, imagecolorallocatealpha($square, 40, 12, 12, $alpha));
        }
        imagepng($square, $target, 6);
        imagedestroy($square);
        imagedestroy($image);
    }

    private function load(string $path, string $mime): \GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };
        if (!$image instanceof \GdImage) {
            throw new MediaValidationException('The image could not be decoded.');
        }

        return $image;
    }

    private function save(\GdImage $image, string $target, string $mime): void
    {
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, $target, 82),
            'image/png' => imagepng($image, $target, 6),
            'image/webp' => imagewebp($image, $target, 80),
            default => false,
        };
        if (!$ok) {
            throw new MediaValidationException('The image could not be encoded.');
        }
    }

    private function preserveTransparency(\GdImage $image, string $mime): void
    {
        if (\in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        }
    }
}
