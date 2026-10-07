<?php

declare(strict_types=1);

namespace App\Repository\Taxonomy;

use App\Entity\Taxonomy\Category;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractTaxonomyRepository<Category>
 */
class CategoryRepository extends AbstractTaxonomyRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    protected function storyAssociation(): string
    {
        return 'categories';
    }
}
