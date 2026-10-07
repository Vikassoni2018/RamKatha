<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountTokenType;
use App\Repository\AccountTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single-use token (email verification / password reset).
 * The public token is "selector.verifier": the selector is looked up, only a SHA-256 hash of
 * the verifier is stored, and it is compared in constant time.
 */
#[ORM\Entity(repositoryClass: AccountTokenRepository::class)]
#[ORM\Table(name: 'account_token')]
#[ORM\UniqueConstraint(name: 'uniq_account_token_selector', columns: ['selector'])]
#[ORM\Index(name: 'idx_account_token_user_type', columns: ['user_id', 'type'])]
#[ORM\Index(name: 'idx_account_token_expires', columns: ['expires_at'])]
class AccountToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 32, enumType: AccountTokenType::class)]
        private AccountTokenType $type,
        #[ORM\Column(length: 32)]
        private string $selector,
        #[ORM\Column(length: 64)]
        private string $verifierHash,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $expiresAt,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): AccountTokenType
    {
        return $this->type;
    }

    public function getSelector(): string
    {
        return $this->selector;
    }

    public function getVerifierHash(): string
    {
        return $this->verifierHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    public function markUsed(\DateTimeImmutable $now): void
    {
        $this->usedAt = $now;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
