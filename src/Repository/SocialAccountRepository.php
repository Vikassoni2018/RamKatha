<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialAccount;
use App\Enum\SocialPlatform;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialAccount>
 */
class SocialAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialAccount::class);
    }

    /** Returns the account row for the platform, creating it with defaults if missing. */
    public function getOrCreate(SocialPlatform $platform): SocialAccount
    {
        $account = $this->findOneBy(['platform' => $platform]);
        if (null === $account) {
            $account = new SocialAccount($platform, $platform->label());
            $this->getEntityManager()->persist($account);
            $this->getEntityManager()->flush();
        }

        return $account;
    }
}
