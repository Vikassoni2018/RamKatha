<?php

declare(strict_types=1);

namespace App\Service\Donation;

use App\Service\Storage\StorageInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generates QR codes locally (endroid/qr-code, no external QR API) and caches them on the public
 * disk under qr/<sha1>.svg — the same payload always maps to the same file.
 */
final class QrCodeService
{
    public function __construct(
        #[Autowire(service: 'app.storage.public')]
        private readonly StorageInterface $publicStorage,
    ) {
    }

    public function svg(string $data, int $size = 320): string
    {
        return (new Builder(
            writer: new SvgWriter(),
            writerOptions: [SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true],
            data: $data,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 12,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->build()->getString();
    }

    public function png(string $data, int $size = 480): string
    {
        if (!\extension_loaded('gd')) {
            throw new \RuntimeException('PNG QR codes require the GD extension; use SVG instead.');
        }

        return (new Builder(
            writer: new PngWriter(),
            data: $data,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 16,
        ))->build()->getString();
    }

    /** Stores (once) and returns the storage key of the SVG for this payload. */
    public function storeSvg(string $data): string
    {
        $key = 'qr/'.sha1($data).'.svg';
        if (!$this->publicStorage->exists($key)) {
            $this->publicStorage->write($key, $this->svg($data));
        }

        return $key;
    }

    public function publicUrl(string $key): ?string
    {
        return $this->publicStorage->publicUrl($key);
    }
}
