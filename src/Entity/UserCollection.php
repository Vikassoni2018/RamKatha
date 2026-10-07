<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\CollectionVisibility;
use App\Repository\UserCollectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** A user-curated list of stories ("Favorites", "Night listening", ...). */
#[ORM\Entity(repositoryClass: UserCollectionRepository::class)]
#[ORM\Table(name: 'user_collection')]
#[ORM\UniqueConstraint(name: 'uniq_collection_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_collection_user_slug', columns: ['user_id', 'slug'])]
#[ORM\Index(name: 'idx_collection_visibility_updated', columns: ['visibility', 'updated_at'])]
#[ORM\HasLifecycleCallbacks]
class UserCollection
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'collection.name.not_blank')]
    #[Assert\Length(min: 2, max: 120)]
    private string $name = '';

    #[ORM\Column(length: 140)]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: CollectionVisibility::class)]
    private CollectionVisibility $visibility = CollectionVisibility::Private;

    /** @var Collection<int, CollectionItem> */
    #[ORM\OneToMany(targetEntity: CollectionItem::class, mappedBy: 'collection', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $items;

    public function __construct(User $user, string $name = '')
    {
        $this->uuid = Uuid::v7();
        $this->user = $user;
        $this->name = $name;
        $this->items = new ArrayCollection();
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function isOwnedBy(?User $user): bool
    {
        return null !== $user && $this->user->getId() === $user->getId();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getVisibility(): CollectionVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(CollectionVisibility $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    /** @return Collection<int, CollectionItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(CollectionItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }

        return $this;
    }

    public function removeItem(CollectionItem $item): static
    {
        $this->items->removeElement($item);

        return $this;
    }

    public function containsStory(Story $story): bool
    {
        foreach ($this->items as $item) {
            if ($item->getStory()->getId() === $story->getId()) {
                return true;
            }
        }

        return false;
    }
}
