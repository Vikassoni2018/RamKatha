<?php

declare(strict_types=1);

namespace App\Entity\Taxonomy;

use App\Repository\Taxonomy\ScriptureRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/** A scripture / granth (e.g. श्रीमद्भगवद्गीता, वाल्मीकि रामायण). */
#[ORM\Entity(repositoryClass: ScriptureRepository::class)]
#[ORM\Table(name: 'scripture')]
#[ORM\UniqueConstraint(name: 'uniq_scripture_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_scripture_active_position', columns: ['is_active', 'position'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'])]
class Scripture extends AbstractTaxonomy
{
    /** Traditional attribution, e.g. "महर्षि वेदव्यास". */
    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $attributedTo = null;

    /** Language of the original text, e.g. "संस्कृत", "अवधी". */
    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $originalLanguage = null;

    /** English attribution for /en pages, e.g. "Sage Ved Vyasa". */
    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $attributedToEn = null;

    /** English name of the original language, e.g. "Sanskrit", "Awadhi". */
    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $originalLanguageEn = null;

    public static function taxonomyKey(): string
    {
        return 'scripture';
    }

    public function getAttributedTo(): ?string
    {
        return $this->attributedTo;
    }

    public function setAttributedTo(?string $attributedTo): static
    {
        $this->attributedTo = $attributedTo;

        return $this;
    }

    public function getOriginalLanguage(): ?string
    {
        return $this->originalLanguage;
    }

    public function setOriginalLanguage(?string $originalLanguage): static
    {
        $this->originalLanguage = $originalLanguage;

        return $this;
    }

    public function getAttributedToEn(): ?string
    {
        return $this->attributedToEn;
    }

    public function setAttributedToEn(?string $attributedToEn): static
    {
        $this->attributedToEn = self::nullIfBlank($attributedToEn);

        return $this;
    }

    public function getOriginalLanguageEn(): ?string
    {
        return $this->originalLanguageEn;
    }

    public function setOriginalLanguageEn(?string $originalLanguageEn): static
    {
        $this->originalLanguageEn = self::nullIfBlank($originalLanguageEn);

        return $this;
    }
}
