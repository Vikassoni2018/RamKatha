<?php

declare(strict_types=1);

namespace App\Entity\Taxonomy;

use App\Repository\Taxonomy\DeityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: DeityRepository::class)]
#[ORM\Table(name: 'deity')]
#[ORM\UniqueConstraint(name: 'uniq_deity_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_deity_active_position', columns: ['is_active', 'position'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'])]
class Deity extends AbstractTaxonomy
{
    public static function taxonomyKey(): string
    {
        return 'deity';
    }
}
