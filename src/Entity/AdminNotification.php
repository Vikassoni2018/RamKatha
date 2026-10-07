<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationLevel;
use App\Enum\NotificationType;
use App\Repository\AdminNotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Internal notification shown to staff in the admin panel (bell + dashboard). */
#[ORM\Entity(repositoryClass: AdminNotificationRepository::class)]
#[ORM\Table(name: 'admin_notification')]
#[ORM\Index(name: 'idx_notification_read_created', columns: ['read_at', 'created_at'])]
#[ORM\Index(name: 'idx_notification_level_created', columns: ['level', 'created_at'])]
class AdminNotification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: NotificationLevel::class)]
    private NotificationLevel $level;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 40, enumType: NotificationType::class)]
        private NotificationType $type,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $message = null,
        /** Relative admin path, e.g. "/admin/stories/12". */
        #[ORM\Column(length: 500, nullable: true)]
        private ?string $link = null,
    ) {
        $this->level = $type->level();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getLevel(): NotificationLevel
    {
        return $this->level;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    public function markRead(\DateTimeImmutable $at): void
    {
        $this->readAt ??= $at;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
