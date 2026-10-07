<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StoryRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Immutable snapshot of a story's content and sources, recorded on significant changes. */
#[ORM\Entity(repositoryClass: StoryRevisionRepository::class)]
#[ORM\Table(name: 'story_revision')]
#[ORM\UniqueConstraint(name: 'uniq_story_revision_version', columns: ['story_id', 'version'])]
class StoryRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>      $sourceSnapshot  source type, notes and references at that time
     * @param array<string, ?string>|null $englishSnapshot English version at that time (null = none)
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Story $story,
        #[ORM\Column]
        private int $version,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $subtitle,
        #[ORM\Column(type: Types::TEXT)]
        private string $excerpt,
        #[ORM\Column(type: Types::TEXT, length: 16777215)]
        private string $body,
        #[ORM\Column(type: Types::JSON)]
        private array $sourceSnapshot,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?User $changedBy,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $changeSummary = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $englishSnapshot = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStory(): Story
    {
        return $this->story;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSubtitle(): ?string
    {
        return $this->subtitle;
    }

    public function getExcerpt(): string
    {
        return $this->excerpt;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return array<string, mixed> */
    public function getSourceSnapshot(): array
    {
        return $this->sourceSnapshot;
    }

    /** @return array<string, ?string>|null title, subtitle, excerpt, body and sourceNotes in English */
    public function getEnglishSnapshot(): ?array
    {
        return $this->englishSnapshot;
    }

    public function getChangedBy(): ?User
    {
        return $this->changedBy;
    }

    public function getChangeSummary(): ?string
    {
        return $this->changeSummary;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
