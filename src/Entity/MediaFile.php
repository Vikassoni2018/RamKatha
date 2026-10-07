<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\MediaFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An uploaded image stored on the public local disk (public/media).
 * File names are UUID based; the original name is kept for display only.
 */
#[ORM\Entity(repositoryClass: MediaFileRepository::class)]
#[ORM\Table(name: 'media_file')]
#[ORM\UniqueConstraint(name: 'uniq_media_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_media_checksum', columns: ['checksum'])]
#[ORM\Index(name: 'idx_media_created', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
class MediaFile
{
    use TimestampableTrait;

    public const VARIANT_THUMB = 'thumb';
    public const VARIANT_CARD = 'card';
    public const VARIANT_LARGE = 'large';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $uuid;

    #[ORM\Column(length: 255)]
    private string $originalName;

    /** Path relative to the public media disk, e.g. "covers/2026/10/0192....jpg". */
    #[ORM\Column(length: 255)]
    private string $storageKey;

    #[ORM\Column(length: 50)]
    private string $mimeType;

    #[ORM\Column]
    private int $fileSize;

    #[ORM\Column(nullable: true)]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    private ?int $height = null;

    #[ORM\Column(length: 64)]
    private string $checksum;

    /**
     * Generated resized variants: {"thumb": {"key": "...", "width": 320, "height": 180}, ...}
     * JSON is appropriate here: the set of variants is flexible derived metadata.
     *
     * @var array<string, array{key: string, width: int, height: int}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $variants = [];

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $altText = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    public function __construct(string $originalName, string $storageKey, string $mimeType, int $fileSize, string $checksum, ?Uuid $uuid = null)
    {
        $this->uuid = $uuid ?? Uuid::v7();
        $this->originalName = mb_substr($originalName, 0, 255);
        $this->storageKey = $storageKey;
        $this->mimeType = $mimeType;
        $this->fileSize = $fileSize;
        $this->checksum = $checksum;
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setDimensions(?int $width, ?int $height): static
    {
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    /** @return array<string, array{key: string, width: int, height: int}> */
    public function getVariants(): array
    {
        return $this->variants;
    }

    /** @param array<string, array{key: string, width: int, height: int}> $variants */
    public function setVariants(array $variants): static
    {
        $this->variants = $variants;

        return $this;
    }

    /** Storage key of a variant, falling back to the original. */
    public function getVariantKey(string $variant): string
    {
        return $this->variants[$variant]['key'] ?? $this->storageKey;
    }

    /** @return array{width: ?int, height: ?int} */
    public function getVariantDimensions(string $variant): array
    {
        if (isset($this->variants[$variant])) {
            return ['width' => $this->variants[$variant]['width'], 'height' => $this->variants[$variant]['height']];
        }

        return ['width' => $this->width, 'height' => $this->height];
    }

    /** @return list<string> every storage key that belongs to this media (original + variants) */
    public function getAllStorageKeys(): array
    {
        return array_values(array_unique(array_merge([$this->storageKey], array_column($this->variants, 'key'))));
    }

    public function getAltText(): ?string
    {
        return $this->altText;
    }

    public function setAltText(?string $altText): static
    {
        $this->altText = null !== $altText ? trim($altText) : null;

        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function __toString(): string
    {
        return $this->originalName;
    }
}
