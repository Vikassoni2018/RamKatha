<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Enum\AccountTokenType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountToken>
 */
class AccountTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountToken::class);
    }

    public function findOneBySelector(string $selector, AccountTokenType $type): ?AccountToken
    {
        return $this->findOneBy(['selector' => $selector, 'type' => $type]);
    }

    public function deleteForUser(User $user, AccountTokenType $type): int
    {
        return $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.user = :user')
            ->andWhere('t.type = :type')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->getQuery()->execute();
    }

    public function deleteExpired(\DateTimeImmutable $now): int
    {
        return $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.expiresAt < :now')
            ->setParameter('now', $now)
            ->getQuery()->execute();
    }
}
