<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\AudioStatus;
use App\Repository\AudioAssetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A generated narration of a story. Files live on the PRIVATE local disk and are streamed by
 * AudioStreamController, so audio of unpublished stories is never publicly reachable.
 */
#[ORM\Entity(repositoryClass: AudioAssetRepository::class)]
#[ORM\Table(name: 'audio_asset')]
#[ORM\UniqueConstraint(name: 'uniq_audio_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_audio_status_updated', columns: ['status', 'updated_at'])]
#[ORM\Index(name: 'idx_audio_story_status', columns: ['story_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
class AudioAsset
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Story $story;

    #[ORM\Column(length: 10)]
    private string $language;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $voice = null;

    #[ORM\Column(length: 50)]
    private string $provider;

    /** Path relative to the private disk, e.g. "audio/2026/10/<uuid>.mp3". */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $storageKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $fileSize = null;

    /** Duration in whole seconds. */
    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    /** SHA-256 of the final audio file. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $checksum = null;

    /** SHA-256 of the pre-processed text that was narrated (detects outdated audio). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $textChecksum = null;

    #[ORM\Column(nullable: true)]
    private ?int $chunkCount = null;

    #[ORM\Column(length: 20, enumType: AudioStatus::class)]
    private AudioStatus $status = AudioStatus::Pending;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $generationStartedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $generationCompletedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $requestedBy = null;

    public function __construct(Story $story, string $language, string $provider, ?string $voice)
    {
        $this->uuid = Uuid::v7();
        $this->story = $story;
        $this->language = $language;
        $this->provider = $provider;
        $this->voice = $voice;
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

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getVoice(): ?string
    {
        return $this->voice;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function setVoice(?string $voice): static
    {
        $this->voice = $voice;

        return $this;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    public function getTextChecksum(): ?string
    {
        return $this->textChecksum;
    }

    public function getChunkCount(): ?int
    {
        return $this->chunkCount;
    }

    public function getStatus(): AudioStatus
    {
        return $this->status;
    }

    public function setStatus(AudioStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getGenerationStartedAt(): ?\DateTimeImmutable
    {
        return $this->generationStartedAt;
    }

    public function getGenerationCompletedAt(): ?\DateTimeImmutable
    {
        return $this->generationCompletedAt;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(?User $requestedBy): static
    {
        $this->requestedBy = $requestedBy;

        return $this;
    }

    public function markQueued(): void
    {
        $this->status = AudioStatus::Queued;
        $this->errorMessage = null;
    }

    public function markProcessing(\DateTimeImmutable $now): void
    {
        $this->status = AudioStatus::Processing;
        ++$this->attempts;
        $this->lastAttemptAt = $now;
        $this->generationStartedAt = $now;
        $this->generationCompletedAt = null;
        $this->errorMessage = null;
    }

    public function markReady(string $storageKey, string $fileName, string $mimeType, int $fileSize, int $durationSeconds, string $checksum, string $textChecksum, int $chunkCount, \DateTimeImmutable $now): void
    {
        $this->status = AudioStatus::Ready;
        $this->storageKey = $storageKey;
        $this->fileName = $fileName;
        $this->mimeType = $mimeType;
        $this->fileSize = $fileSize;
        $this->durationSeconds = $durationSeconds;
        $this->checksum = $checksum;
        $this->textChecksum = $textChecksum;
        $this->chunkCount = $chunkCount;
        $this->generationCompletedAt = $now;
        $this->errorMessage = null;
    }

    public function markFailed(string $error, \DateTimeImmutable $now): void
    {
        $this->status = AudioStatus::Failed;
        $this->errorMessage = mb_substr($error, 0, 5000);
        $this->generationCompletedAt = $now;
    }

    public function disable(): void
    {
        $this->status = AudioStatus::Disabled;
    }
}
