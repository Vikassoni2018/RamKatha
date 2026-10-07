<?php

declare(strict_types=1);

namespace App\Entity\Taxonomy;

use App\Repository\Taxonomy\TagRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tag')]
#[ORM\UniqueConstraint(name: 'uniq_tag_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'])]
class Tag extends AbstractTaxonomy
{
    public static function taxonomyKey(): string
    {
        return 'tag';
    }
}
