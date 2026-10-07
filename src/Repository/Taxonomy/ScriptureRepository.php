<?php

declare(strict_types=1);

namespace App\Repository\Taxonomy;

use App\Entity\Taxonomy\Scripture;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractTaxonomyRepository<Scripture>
 */
class ScriptureRepository extends AbstractTaxonomyRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Scripture::class);
    }

    protected function storyAssociation(): string
    {
        return 'scripture';
    }
}
