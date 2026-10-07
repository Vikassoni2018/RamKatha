<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\SocialPostStatus;
use App\Repository\SocialPostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SocialPostRepository::class)]
#[ORM\Table(name: 'social_post')]
#[ORM\UniqueConstraint(name: 'uniq_social_post_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_social_status_scheduled', columns: ['status', 'scheduled_at'])]
#[ORM\Index(name: 'idx_social_created', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
class SocialPost
{
    use TimestampableTrait;

    public const MAX_AUTO_ATTEMPTS = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Story $story;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private SocialAccount $account;

    #[ORM\Column(length: 20, enumType: SocialPostStatus::class)]
    private SocialPostStatus $status = SocialPostStatus::Draft;

    /** Final caption, editable by the admin before publication. */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 8000)]
    private string $message = '';

    #[ORM\Column(length: 500)]
    private string $link = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $externalPostId = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $attemptCount = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    /** Whether the last error is worth retrying automatically (network / 5xx / rate limit). */
    #[ORM\Column(options: ['default' => false])]
    private bool $lastErrorRetryable = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /** @var Collection<int, SocialPostAttempt> */
    #[ORM\OneToMany(targetEntity: SocialPostAttempt::class, mappedBy: 'post', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['attemptedAt' => 'DESC'])]
    private Collection $attempts;

    public function __construct(Story $story, SocialAccount $account)
    {
        $this->uuid = Uuid::v7();
        $this->story = $story;
        $this->account = $account;
        $this->attempts = new ArrayCollection();
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

    public function getAccount(): SocialAccount
    {
        return $this->account;
    }

    public function getStatus(): SocialPostStatus
    {
        return $this->status;
    }

    public function setStatus(SocialPostStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getLink(): string
    {
        return $this->link;
    }

    public function setLink(string $link): static
    {
        $this->link = $link;

        return $this;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getExternalPostId(): ?string
    {
        return $this->externalPostId;
    }

    public function getExternalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function isLastErrorRetryable(): bool
    {
        return $this->lastErrorRetryable;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
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

    /** @return Collection<int, SocialPostAttempt> */
    public function getAttempts(): Collection
    {
        return $this->attempts;
    }

    public function recordAttempt(SocialPostAttempt $attempt): void
    {
        $this->attempts->add($attempt);
        ++$this->attemptCount;
        $this->lastAttemptAt = $attempt->getAttemptedAt();
    }

    public function markPublished(string $externalPostId, ?string $externalUrl, \DateTimeImmutable $at): void
    {
        $this->status = SocialPostStatus::Published;
        $this->externalPostId = $externalPostId;
        $this->externalUrl = $externalUrl;
        $this->publishedAt = $at;
        $this->lastError = null;
        $this->lastErrorRetryable = false;
    }

    public function markFailed(string $error, bool $retryable): void
    {
        $this->status = SocialPostStatus::Failed;
        $this->lastError = mb_substr($error, 0, 5000);
        $this->lastErrorRetryable = $retryable;
    }

    public function canAutoRetry(): bool
    {
        return SocialPostStatus::Failed === $this->status && $this->lastErrorRetryable && $this->attemptCount < self::MAX_AUTO_ATTEMPTS;
    }
}
