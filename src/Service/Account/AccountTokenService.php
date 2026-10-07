<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Enum\AccountTokenType;
use App\Repository\AccountTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Secure single-use tokens. The token handed to the user is "selector.verifier" (both random);
 * only sha256(verifier) is stored, compared with hash_equals, and expired/used tokens are rejected.
 * Issuing a new token invalidates previous ones of the same type.
 */
final class AccountTokenService
{
    public function __construct(
        private readonly AccountTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function issue(User $user, AccountTokenType $type): string
    {
        $this->tokens->deleteForUser($user, $type);

        $selector = bin2hex(random_bytes(12));
        $verifier = $this->base64Url(random_bytes(32));
        $token = new AccountToken(
            $user,
            $type,
            $selector,
            hash('sha256', $verifier),
            $this->clock->now()->modify('+'.$type->ttl().' seconds'),
        );
        $this->em->persist($token);
        $this->em->flush();

        return $selector.'.'.$verifier;
    }

    /** Returns the user for a valid token without consuming it (e.g. to render the reset form). */
    public function peek(string $publicToken, AccountTokenType $type): ?User
    {
        return $this->find($publicToken, $type)?->getUser();
    }

    /** Validates and consumes the token. */
    public function consume(string $publicToken, AccountTokenType $type): ?User
    {
        $token = $this->find($publicToken, $type);
        if (null === $token) {
            return null;
        }
        $token->markUsed($this->clock->now());
        $this->em->flush();

        return $token->getUser();
    }

    private function find(string $publicToken, AccountTokenType $type): ?AccountToken
    {
        $parts = explode('.', $publicToken, 2);
        if (2 !== \count($parts) || !preg_match('/^[a-f0-9]{24}$/', $parts[0]) || '' === $parts[1]) {
            return null;
        }
        [$selector, $verifier] = $parts;
        $token = $this->tokens->findOneBySelector($selector, $type);
        if (null === $token
            || $token->isUsed()
            || $token->isExpired($this->clock->now())
            || !hash_equals($token->getVerifierHash(), hash('sha256', $verifier))
            || !$token->getUser()->isActive()) {
            return null;
        }

        return $token;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
