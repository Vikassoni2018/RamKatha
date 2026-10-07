<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\PageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Editable static page (About, Privacy, Terms, Disclaimer, ...). Body is sanitised HTML. */
#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\Table(name: 'page')]
#[ORM\UniqueConstraint(name: 'uniq_page_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_page_footer', columns: ['is_published', 'show_in_footer', 'position'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'])]
class Page
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
    private string $slug = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $body = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $seoTitle = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $seoDescription = null;

    // English version for /en (optional; English pages show the Hindi page until title and body exist).

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $titleEn = null;

    #[ORM\Column(type: Types::TEXT, length: 16777215, nullable: true)]
    private ?string $bodyEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $seoTitleEn = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $seoDescriptionEn = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isPublished = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $showInFooter = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct()
    {
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = (string) $body;

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

    /** True when the page can be read in English (title and body are present). */
    public function hasEnglishVersion(): bool
    {
        return null !== $this->titleEn && null !== $this->bodyEn;
    }

    /** Title and body go together: a page is either translated or shown in Hindi. */
    #[Assert\Callback]
    public function validateEnglishVersion(ExecutionContextInterface $context): void
    {
        if ((null === $this->titleEn) === (null === $this->bodyEn)) {
            return;
        }
        $context->buildViolation('The English version needs both a title and a body (or leave both empty).')
            ->atPath(null === $this->titleEn ? 'titleEn' : 'bodyEn')
            ->addViolation();
    }

    public function getTitleEn(): ?string
    {
        return $this->titleEn;
    }

    public function setTitleEn(?string $titleEn): static
    {
        $titleEn = null !== $titleEn ? trim($titleEn) : null;
        $this->titleEn = '' === $titleEn ? null : $titleEn;

        return $this;
    }

    public function getBodyEn(): ?string
    {
        return $this->bodyEn;
    }

    public function setBodyEn(?string $bodyEn): static
    {
        $this->bodyEn = null !== $bodyEn && '' !== trim(strip_tags($bodyEn)) ? $bodyEn : null;

        return $this;
    }

    public function getSeoTitleEn(): ?string
    {
        return $this->seoTitleEn;
    }

    public function setSeoTitleEn(?string $seoTitleEn): static
    {
        $this->seoTitleEn = null !== $seoTitleEn && '' !== trim($seoTitleEn) ? trim($seoTitleEn) : null;

        return $this;
    }

    public function getSeoDescriptionEn(): ?string
    {
        return $this->seoDescriptionEn;
    }

    public function setSeoDescriptionEn(?string $seoDescriptionEn): static
    {
        $this->seoDescriptionEn = null !== $seoDescriptionEn && '' !== trim($seoDescriptionEn) ? trim($seoDescriptionEn) : null;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->isPublished;
    }

    public function setIsPublished(bool $isPublished): static
    {
        $this->isPublished = $isPublished;

        return $this;
    }

    public function isShowInFooter(): bool
    {
        return $this->showInFooter;
    }

    public function setShowInFooter(bool $showInFooter): static
    {
        $this->showInFooter = $showInFooter;

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

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }
}
