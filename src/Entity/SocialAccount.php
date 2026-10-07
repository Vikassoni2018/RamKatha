<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\SocialPlatform;
use App\Repository\SocialAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Non-secret publishing preferences for a social platform.
 * Credentials (page id, access token, API version) are read from environment variables only and
 * are never stored in the database or rendered in HTML.
 */
#[ORM\Entity(repositoryClass: SocialAccountRepository::class)]
#[ORM\Table(name: 'social_account')]
#[ORM\UniqueConstraint(name: 'uniq_social_account_platform', columns: ['platform'])]
#[ORM\HasLifecycleCallbacks]
class SocialAccount
{
    use TimestampableTrait;

    public const DEFAULT_TEMPLATE = "{{story_title}}\n\n{{story_excerpt}}\n\nपूरी कथा पढ़ें और सुनें: {{story_url}}\n\nस्रोत: {{source_name}}";

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: SocialPlatform::class)]
    private SocialPlatform $platform;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private string $displayName;

    /** Admin toggle. Effective only when the platform credentials are configured in ENV. */
    #[ORM\Column(options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 5000)]
    private string $defaultTemplate = self::DEFAULT_TEMPLATE;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $defaultHashtags = null;

    /** Name returned by the platform during the last connection test. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $remoteName = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lastCheckResult = null;

    public function __construct(SocialPlatform $platform, string $displayName)
    {
        $this->platform = $platform;
        $this->displayName = $displayName;
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlatform(): SocialPlatform
    {
        return $this->platform;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getDefaultTemplate(): string
    {
        return $this->defaultTemplate;
    }

    public function setDefaultTemplate(string $defaultTemplate): static
    {
        $this->defaultTemplate = $defaultTemplate;

        return $this;
    }

    public function getDefaultHashtags(): ?string
    {
        return $this->defaultHashtags;
    }

    public function setDefaultHashtags(?string $defaultHashtags): static
    {
        $this->defaultHashtags = $defaultHashtags;

        return $this;
    }

    public function getRemoteName(): ?string
    {
        return $this->remoteName;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function getLastCheckResult(): ?string
    {
        return $this->lastCheckResult;
    }

    public function recordConnectionCheck(bool $ok, string $result, ?string $remoteName, \DateTimeImmutable $at): void
    {
        $this->lastCheckedAt = $at;
        $this->lastCheckResult = mb_substr(($ok ? 'OK: ' : 'Error: ').$result, 0, 500);
        if ($ok) {
            $this->remoteName = $remoteName;
        }
    }
}
