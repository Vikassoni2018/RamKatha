<?php

declare(strict_types=1);

namespace App\Entity\Taxonomy;

use App\Repository\Taxonomy\CategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'category')]
#[ORM\UniqueConstraint(name: 'uniq_category_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_category_active_position', columns: ['is_active', 'position'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'])]
class Category extends AbstractTaxonomy
{
    public static function taxonomyKey(): string
    {
        return 'category';
    }
}
