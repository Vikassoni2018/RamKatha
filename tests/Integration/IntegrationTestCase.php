<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Story;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Boots the real container against the fixture database. Every test runs inside a transaction
 * that DAMA rolls back afterwards, so tests may freely modify data.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        return self::getContainer()->get($id);
    }

    /** Fixture user by short name ("admin", "editor", ...) or full e-mail. */
    protected function user(string $name): User
    {
        $email = str_contains($name, '@') ? $name : $name.'@example.test';

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email])
            ?? throw new \LogicException(\sprintf('Fixture user "%s" not found — run the test database setup (see README).', $email));
    }

    protected function story(string $slug): Story
    {
        return $this->em->getRepository(Story::class)->findOneBy(['slug' => $slug])
            ?? throw new \LogicException(\sprintf('Fixture story "%s" not found — run the test database setup (see README).', $slug));
    }
}
