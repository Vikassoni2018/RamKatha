<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PronunciationRule;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PronunciationRule>
 */
class PronunciationRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PronunciationRule::class);
    }

    /**
     * Enabled rules in deterministic application order: priority DESC, then id ASC.
     *
     * @return list<PronunciationRule>
     */
    public function findEnabledForLanguage(string $language): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.enabled = true')
            ->andWhere('r.language = :language')
            ->setParameter('language', $language)
            ->orderBy('r.priority', 'DESC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return Paginator<PronunciationRule> */
    public function searchPaginated(?string $query, ?string $language, int $page, int $perPage = 50): Paginator
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.priority', 'DESC')
            ->addOrderBy('r.id', 'ASC');
        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('r.sourceText LIKE :q OR r.replacementText LIKE :q OR r.notes LIKE :q')
                ->setParameter('q', '%'.addcslashes(trim($query), '%_').'%');
        }
        if (null !== $language && '' !== $language) {
            $qb->andWhere('r.language = :language')->setParameter('language', $language);
        }

        return Paginator::fromQuery($qb, $page, $perPage);
    }
}
