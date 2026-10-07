<?php

declare(strict_types=1);

namespace App\Repository\Taxonomy;

use App\Entity\Taxonomy\Tag;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractTaxonomyRepository<Tag>
 */
class TagRepository extends AbstractTaxonomyRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    protected function storyAssociation(): string
    {
        return 'tags';
    }
}
