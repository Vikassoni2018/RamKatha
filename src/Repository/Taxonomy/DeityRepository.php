<?php

declare(strict_types=1);

namespace App\Repository\Taxonomy;

use App\Entity\Taxonomy\Deity;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractTaxonomyRepository<Deity>
 */
class DeityRepository extends AbstractTaxonomyRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Deity::class);
    }

    protected function storyAssociation(): string
    {
        return 'deities';
    }
}
