<?php

declare(strict_types=1);

namespace App\Entity\Taxonomy;

use App\Repository\Taxonomy\FestivalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A festival / vrat. Hindu festival dates follow the lunar calendar, so the next date is maintained
 * by editors (nextOccurrenceOn) instead of being computed.
 */
#[ORM\Entity(repositoryClass: FestivalRepository::class)]
#[ORM\Table(name: 'festival')]
#[ORM\UniqueConstraint(name: 'uniq_festival_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_festival_next', columns: ['is_active', 'next_occurrence_on'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'])]
class Festival extends AbstractTaxonomy
{
    /** Traditional period, e.g. "भाद्रपद कृष्ण अष्टमी". */
    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $typicalPeriod = null;

    /** English period for /en pages, e.g. "Krishna Ashtami of Bhadrapada". */
    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $typicalPeriodEn = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextOccurrenceOn = null;

    public static function taxonomyKey(): string
    {
        return 'festival';
    }

    public function getTypicalPeriod(): ?string
    {
        return $this->typicalPeriod;
    }

    public function setTypicalPeriod(?string $typicalPeriod): static
    {
        $this->typicalPeriod = $typicalPeriod;

        return $this;
    }

    public function getTypicalPeriodEn(): ?string
    {
        return $this->typicalPeriodEn;
    }

    public function setTypicalPeriodEn(?string $typicalPeriodEn): static
    {
        $this->typicalPeriodEn = self::nullIfBlank($typicalPeriodEn);

        return $this;
    }
    public function getNextOccurrenceOn(): ?\DateTimeImmutable
    {
        return $this->nextOccurrenceOn;
    }

    public function setNextOccurrenceOn(?\DateTimeImmutable $nextOccurrenceOn): static
    {
        $this->nextOccurrenceOn = $nextOccurrenceOn;

        return $this;
    }
}
