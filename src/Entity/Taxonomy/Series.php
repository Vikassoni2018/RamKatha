<?php

declare(strict_types=1);

namespace App\Entity\Taxonomy;

use App\Repository\Taxonomy\SeriesRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/** An ordered sequence of stories (Story::$series + Story::$seriesPosition). */
#[ORM\Entity(repositoryClass: SeriesRepository::class)]
#[ORM\Table(name: 'series')]
#[ORM\UniqueConstraint(name: 'uniq_series_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_series_active_position', columns: ['is_active', 'position'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'])]
class Series extends AbstractTaxonomy
{
    #[ORM\Column(options: ['default' => false])]
    private bool $isCompleted = false;

    public static function taxonomyKey(): string
    {
        return 'series';
    }

    public function isCompleted(): bool
    {
        return $this->isCompleted;
    }

    public function setIsCompleted(bool $isCompleted): static
    {
        $this->isCompleted = $isCompleted;

        return $this;
    }
}
