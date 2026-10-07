<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'collection_item')]
#[ORM\UniqueConstraint(name: 'uniq_collection_item_story', columns: ['collection_id', 'story_id'])]
#[ORM\Index(name: 'idx_collection_item_position', columns: ['collection_id', 'position'])]
class CollectionItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'items')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private UserCollection $collection,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Story $story,
        #[ORM\Column]
        private int $position = 0,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCollection(): UserCollection
    {
        return $this->collection;
    }

    public function getStory(): Story
    {
        return $this->story;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
