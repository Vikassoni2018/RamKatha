<?php

declare(strict_types=1);

namespace App\DataFixtures;

/**
 * Generates placeholder media for development fixtures without any external service:
 *  - abstract warm-coloured cover images (GD, JPEG)
 *  - silent MP3 files (valid MPEG-1 Layer III frames) so the audio player can be demonstrated
 *    without a TTS engine. They are clearly labelled "demo-silence" in the admin.
 */
final class DemoMediaFactory
{
    /** Palettes: [background top, background bottom, accent, glow] */
    private const PALETTES = [
        [[122, 31, 43], [74, 18, 26], [242, 162, 74], [255, 217, 168]],
        [[35, 64, 98], [18, 33, 56], [224, 122, 31], [255, 225, 180]],
        [[171, 84, 18], [110, 40, 10], [255, 214, 140], [255, 240, 210]],
        [[34, 78, 76], [16, 44, 46], [233, 188, 90], [255, 236, 190]],
        [[148, 62, 24], [90, 28, 16], [255, 176, 92], [255, 228, 186]],
        [[70, 40, 90], [36, 18, 50], [240, 170, 80], [250, 220, 180]],
    ];

    public function coverImage(int $variant, string $path, int $width = 1600, int $height = 1000): void
    {
        [$top, $bottom, $accent, $glow] = self::PALETTES[$variant % \count(self::PALETTES)];
        $im = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; ++$y) {
            $t = $y / $height;
            $c = imagecolorallocate($im, (int) ($top[0] + ($bottom[0] - $top[0]) * $t), (int) ($top[1] + ($bottom[1] - $top[1]) * $t), (int) ($top[2] + ($bottom[2] - $top[2]) * $t));
            imageline($im, 0, $y, $width, $y, $c);
        }

        // Soft glow circles
        imagealphablending($im, true);
        $cx = (int) ($width * (0.62 + 0.08 * ($variant % 3)));
        $cy = (int) ($height * 0.42);
        for ($r = 520; $r > 0; $r -= 8) {
            $alpha = (int) (127 - (520 - $r) / 520 * 40);
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, imagecolorallocatealpha($im, $glow[0], $glow[1], $glow[2], max(87, $alpha)));
        }

        // Stylised diya (lamp) silhouette
        $accentColor = imagecolorallocate($im, $accent[0], $accent[1], $accent[2]);
        $flame = imagecolorallocate($im, 255, 236, 200);
        $baseY = (int) ($height * 0.72);
        imagefilledarc($im, $cx, $baseY, 360, 200, 0, 180, $accentColor, IMG_ARC_PIE);
        imagefilledrectangle($im, $cx - 180, $baseY - 4, $cx + 180, $baseY + 4, $accentColor);
        imagefilledellipse($im, $cx, $baseY - 110, 70, 120, $flame);
        imagefilledpolygon($im, [$cx - 30, $baseY - 120, $cx + 30, $baseY - 120, $cx, $baseY - 230], $flame);

        // Subtle concentric arcs (mandala hint)
        $line = imagecolorallocatealpha($im, $glow[0], $glow[1], $glow[2], 100);
        imagesetthickness($im, 2);
        for ($i = 1; $i <= 6; ++$i) {
            imagearc($im, (int) ($width * 0.18), (int) ($height * 0.85), 180 * $i, 180 * $i, 180, 360, $line);
        }

        imagejpeg($im, $path, 85);
        imagedestroy($im);
    }

    /** Silent CBR MP3 (MPEG-1 Layer III, 32 kbit/s, 44.1 kHz, mono). */
    public function silentMp3(int $seconds): string
    {
        // Header FF FB 10 C4: sync, MPEG-1, Layer III, no CRC, 32 kbit/s, 44100 Hz, no padding, mono.
        $frameLength = (int) floor(144 * 32000 / 44100); // 104 bytes
        $frame = "\xFF\xFB\x10\xC4".str_repeat("\x00", $frameLength - 4);
        $frames = (int) ceil($seconds * 44100 / 1152);

        return str_repeat($frame, $frames);
    }
}
