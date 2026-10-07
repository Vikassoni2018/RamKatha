<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\AssetStatus;
use App\Repository\SocialAssetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Generated social media asset (currently: audiogram video) stored on the public disk. */
#[ORM\Entity(repositoryClass: SocialAssetRepository::class)]
#[ORM\Table(name: 'social_asset')]
#[ORM\UniqueConstraint(name: 'uniq_social_asset_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_social_asset_story', columns: ['story_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class SocialAsset
{
    use TimestampableTrait;

    public const TYPE_AUDIOGRAM = 'audiogram';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $uuid;

    #[ORM\Column(length: 20, enumType: AssetStatus::class)]
    private AssetStatus $status = AssetStatus::Pending;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $storageKey = null;

    #[ORM\Column(nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Story $story,
        #[ORM\Column(length: 30)]
        private string $type = self::TYPE_AUDIOGRAM,
    ) {
        $this->uuid = Uuid::v7();
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

    public function getStory(): Story
    {
        return $this->story;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): AssetStatus
    {
        return $this->status;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function markProcessing(): void
    {
        $this->status = AssetStatus::Processing;
        $this->errorMessage = null;
    }

    public function markReady(string $storageKey, int $fileSize, int $durationSeconds): void
    {
        $this->status = AssetStatus::Ready;
        $this->storageKey = $storageKey;
        $this->fileSize = $fileSize;
        $this->durationSeconds = $durationSeconds;
        $this->errorMessage = null;
    }

    public function markFailed(string $error): void
    {
        $this->status = AssetStatus::Failed;
        $this->errorMessage = mb_substr($error, 0, 5000);
    }
}
