<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StorySlugHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Previous slugs of a story; old URLs are answered with a 301 redirect. */
#[ORM\Entity(repositoryClass: StorySlugHistoryRepository::class)]
#[ORM\Table(name: 'story_slug_history')]
#[ORM\UniqueConstraint(name: 'uniq_story_slug_history_slug', columns: ['slug'])]
class StorySlugHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Story $story,
        #[ORM\Column(length: 190)]
        private string $slug,
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

    public function setStory(Story $story): static
    {
        $this->story = $story;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
