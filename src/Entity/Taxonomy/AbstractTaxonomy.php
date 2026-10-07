<?php

declare(strict_types=1);

namespace App\Entity\Taxonomy;

use App\Entity\MediaFile;
use App\Entity\Traits\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Common fields of all browseable taxonomies (category, tag, deity, scripture, series, festival).
 * Names are stored in the content language (Hindi); nameEn holds an optional English/romanised name
 * and the other *En fields optional English texts for /en pages (see ContentLocalizer).
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractTaxonomy implements TaxonomyInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    protected string $name = '';

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    protected ?string $nameEn = null;

    #[ORM\Column(length: 160)]
    #[Assert\Length(max: 160)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Slug may contain lowercase letters, digits and hyphens only.')]
    protected string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 5000)]
    protected ?string $description = null;

    /** English description for /en pages (falls back to the Hindi description). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 5000)]
    protected ?string $descriptionEn = null;

    #[ORM\Column(options: ['default' => 0])]
    protected int $position = 0;

    #[ORM\Column(options: ['default' => true])]
    protected bool $isActive = true;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    protected ?string $seoTitle = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    protected ?string $seoDescription = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    protected ?string $seoTitleEn = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    protected ?string $seoDescriptionEn = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    protected ?MediaFile $coverImage = null;

    public function __construct(string $name = '', string $slug = '')
    {
        $this->name = $name;
        $this->slug = $slug;
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getNameEn(): ?string
    {
        return $this->nameEn;
    }

    public function setNameEn(?string $nameEn): static
    {
        $this->nameEn = null !== $nameEn ? trim($nameEn) : null;

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

    public function getDescriptionEn(): ?string
    {
        return $this->descriptionEn;
    }

    public function setDescriptionEn(?string $descriptionEn): static
    {
        $this->descriptionEn = self::nullIfBlank($descriptionEn);

        return $this;
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getSeoTitle(): ?string
    {
        return $this->seoTitle;
    }

    public function setSeoTitle(?string $seoTitle): static
    {
        $this->seoTitle = $seoTitle;

        return $this;
    }

    public function getSeoDescription(): ?string
    {
        return $this->seoDescription;
    }

    public function setSeoDescription(?string $seoDescription): static
    {
        $this->seoDescription = $seoDescription;

        return $this;
    }

    public function getSeoTitleEn(): ?string
    {
        return $this->seoTitleEn;
    }

    public function setSeoTitleEn(?string $seoTitleEn): static
    {
        $this->seoTitleEn = self::nullIfBlank($seoTitleEn);

        return $this;
    }

    public function getSeoDescriptionEn(): ?string
    {
        return $this->seoDescriptionEn;
    }

    public function setSeoDescriptionEn(?string $seoDescriptionEn): static
    {
        $this->seoDescriptionEn = self::nullIfBlank($seoDescriptionEn);

        return $this;
    }

    public function getCoverImage(): ?MediaFile
    {
        return $this->coverImage;
    }

    public function setCoverImage(?MediaFile $coverImage): static
    {
        $this->coverImage = $coverImage;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    protected static function nullIfBlank(?string $value): ?string
    {
        $value = null !== $value ? trim($value) : null;

        return '' === $value ? null : $value;
    }
}
