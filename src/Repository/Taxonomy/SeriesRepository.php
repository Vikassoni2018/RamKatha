<?php

declare(strict_types=1);

namespace App\Repository\Taxonomy;

use App\Entity\Taxonomy\Series;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractTaxonomyRepository<Series>
 */
class SeriesRepository extends AbstractTaxonomyRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Series::class);
    }

    protected function storyAssociation(): string
    {
        return 'series';
    }
}
