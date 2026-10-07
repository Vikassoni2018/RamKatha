<?php

declare(strict_types=1);

namespace App\Repository\Taxonomy;

use App\Entity\Taxonomy\Festival;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractTaxonomyRepository<Festival>
 */
class FestivalRepository extends AbstractTaxonomyRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Festival::class);
    }

    protected function storyAssociation(): string
    {
        return 'festivals';
    }

    /** Next active festival occurring within the given window (inclusive). */
    public function findNextUpcoming(\DateTimeImmutable $today, int $withinDays = 45): ?Festival
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.isActive = true')
            ->andWhere('f.nextOccurrenceOn >= :today')
            ->andWhere('f.nextOccurrenceOn <= :until')
            ->setParameter('today', $today->setTime(0, 0))
            ->setParameter('until', $today->modify('+'.$withinDays.' days'))
            ->orderBy('f.nextOccurrenceOn', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
