<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuditAction;
use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only record of an important administrative action.
 * Metadata never contains passwords, tokens or other secrets (filtered by AuditLogger).
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class, readOnly: true)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_audit_entity', columns: ['entity_type', 'entity_id'])]
#[ORM\Index(name: 'idx_audit_action_created', columns: ['action', 'created_at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        #[ORM\Column(length: 64, enumType: AuditAction::class)]
        private AuditAction $action,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?User $actor = null,
        /** Snapshot of the actor identifier (email or "system"), kept even if the user is deleted. */
        #[ORM\Column(length: 180)]
        private string $actorIdentifier = 'system',
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $entityType = null,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $entityId = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $metadata = null,
        #[ORM\Column(length: 45, nullable: true)]
        private ?string $ipAddress = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorIdentifier(): string
    {
        return $this->actorIdentifier;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
