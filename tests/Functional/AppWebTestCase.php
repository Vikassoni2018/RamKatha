<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Story;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP-level tests against the fixture database (each test is rolled back by DAMA).
 *
 * CSRF in this application is stateless (same-origin check): the site's JavaScript sends the
 * placeholder token "csrf-token" and browsers add Sec-Fetch-Site/Origin headers. The helpers
 * below reproduce exactly that, so tests also prove that requests WITHOUT it are rejected.
 */
abstract class AppWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function user(string $name): User
    {
        $email = str_contains($name, '@') ? $name : $name.'@example.test';

        return $this->em()->getRepository(User::class)->findOneBy(['email' => $email])
            ?? throw new \LogicException(\sprintf('Fixture user "%s" not found — run "composer test:setup".', $email));
    }

    protected function story(string $slug): Story
    {
        return $this->em()->getRepository(Story::class)->findOneBy(['slug' => $slug])
            ?? throw new \LogicException(\sprintf('Fixture story "%s" not found — run "composer test:setup".', $slug));
    }

    protected function loginAs(string $name): User
    {
        $user = $this->user($name);
        $this->client->loginUser($user);

        return $user;
    }

    /**
     * JSON request as sent by the site's own JavaScript.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $server  overrides (e.g. HTTP_SEC_FETCH_SITE => cross-site)
     *
     * @return array<string, mixed>|null decoded body
     */
    protected function json(string $method, string $uri, array $payload = [], array $server = []): ?array
    {
        $this->client->request($method, $uri, [], [], $server + [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], (string) json_encode($payload));

        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? null : json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Form POST as sent by an admin action button.
     *
     * @param array<string, string> $params
     * @param array<string, string> $server
     */
    protected function post(string $uri, array $params = [], array $server = []): void
    {
        $this->client->request('POST', $uri, $params + ['_csrf_token' => 'csrf-token'], [], $server + ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    /** Fresh copy from the database (the kernel — and its entity manager — reboots between requests). */
    protected function reload(Story $story): Story
    {
        $em = $this->em();
        $em->clear();

        return $em->find(Story::class, $story->getId()) ?? throw new \LogicException('Story vanished.');
    }
}
