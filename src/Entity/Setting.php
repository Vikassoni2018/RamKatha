<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stored value of a typed application setting. Definitions (type, default, validation) live in
 * App\Service\Settings\SettingsRegistry; the value is stored JSON-encoded.
 * Secrets never live here — they come from environment variables.
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'app_setting')]
#[ORM\UniqueConstraint(name: 'uniq_setting_name', columns: ['name'])]
class Setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct(
        #[ORM\Column(length: 100)]
        private string $name,
        mixed $value,
    ) {
        $this->setValue($value);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): mixed
    {
        return json_decode($this->value, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function setValue(mixed $value, ?User $by = null): void
    {
        $this->value = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $this->updatedAt = new \DateTimeImmutable();
        $this->updatedBy = $by;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }
}
