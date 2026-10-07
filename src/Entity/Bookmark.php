<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BookmarkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookmarkRepository::class)]
#[ORM\Table(name: 'bookmark')]
#[ORM\UniqueConstraint(name: 'uniq_bookmark_user_story', columns: ['user_id', 'story_id'])]
#[ORM\Index(name: 'idx_bookmark_user_created', columns: ['user_id', 'created_at'])]
class Bookmark
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
        private User $user,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Story $story,
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

    public function getStory(): Story
    {
        return $this->story;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
