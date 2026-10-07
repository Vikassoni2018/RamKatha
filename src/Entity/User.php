<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\UserRepository;
use App\Security\Role;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_user_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_user_created', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'account.email.taken')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $uuid;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    private string $displayName = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    public function __construct()
    {
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = Role::USER;

        return array_values(array_unique($roles));
    }

    /** Roles explicitly stored (without the implicit ROLE_USER). @return list<string> */
    public function getAssignedRoles(): array
    {
        return array_values(array_diff($this->roles, [Role::USER]));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique(array_diff($roles, [Role::USER])));

        return $this;
    }

    public function hasRole(string $role): bool
    {
        return \in_array($role, $this->getRoles(), true);
    }

    public function isStaff(): bool
    {
        return [] !== array_intersect($this->roles, array_keys(Role::ASSIGNABLE));
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = trim($displayName);

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
        $this->deactivatedAt = new \DateTimeImmutable();
    }

    public function activate(): void
    {
        $this->isActive = true;
        $this->deactivatedAt = null;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    /**
     * Called when the session user is refreshed on every request: a changed password, changed
     * roles or a deactivated account ends existing sessions immediately.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self || !$user->isActive()) {
            return false;
        }
        // The session copy only holds a CRC32C checksum of the hash (see __serialize()).
        if ($this->password !== $user->getPassword() && hash('crc32c', $user->getPassword()) !== $this->password) {
            return false;
        }
        $originalRoles = $this->getRoles();
        $currentRoles = $user->getRoles();
        sort($originalRoles);
        sort($currentRoles);

        return $originalRoles === $currentRoles && $this->getUserIdentifier() === $user->getUserIdentifier();
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // No plain-text credentials are ever stored on this object.
    }

    public function __toString(): string
    {
        return $this->displayName ?: $this->email;
    }
}
