<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Enum\AccountTokenType;
use App\Service\Account\AccountTokenService;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class AccountTokenServiceTest extends IntegrationTestCase
{
    use ClockSensitiveTrait;

    public function testTokenIsSingleUse(): void
    {
        $tokens = $this->service(AccountTokenService::class);
        $user = $this->user('user');

        $token = $tokens->issue($user, AccountTokenType::PasswordReset);
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}\.[A-Za-z0-9_-]{43}$/', $token);

        self::assertSame($user->getId(), $tokens->peek($token, AccountTokenType::PasswordReset)?->getId(), 'Peeking does not consume.');
        self::assertSame($user->getId(), $tokens->consume($token, AccountTokenType::PasswordReset)?->getId());
        self::assertNull($tokens->consume($token, AccountTokenType::PasswordReset), 'A used token cannot be replayed.');
    }

    public function testRejectsTamperedOrMisusedTokens(): void
    {
        $tokens = $this->service(AccountTokenService::class);
        $token = $tokens->issue($this->user('user'), AccountTokenType::PasswordReset);
        [$selector, $verifier] = explode('.', $token);

        self::assertNull($tokens->peek($selector.'.'.strrev($verifier), AccountTokenType::PasswordReset));
        self::assertNull($tokens->peek($selector, AccountTokenType::PasswordReset));
        self::assertNull($tokens->peek('', AccountTokenType::PasswordReset));
        self::assertNull($tokens->peek($token, AccountTokenType::EmailVerification), 'Tokens are bound to their purpose.');
    }

    public function testExpiredTokensAreRejected(): void
    {
        $tokens = $this->service(AccountTokenService::class);
        $token = $tokens->issue($this->user('user'), AccountTokenType::PasswordReset);

        self::mockTime('+'.(AccountTokenType::PasswordReset->ttl() + 60).' seconds');

        self::assertNull($tokens->consume($token, AccountTokenType::PasswordReset));
    }

    public function testIssuingANewTokenRevokesThePreviousOne(): void
    {
        $tokens = $this->service(AccountTokenService::class);
        $user = $this->user('user');

        $first = $tokens->issue($user, AccountTokenType::PasswordReset);
        $second = $tokens->issue($user, AccountTokenType::PasswordReset);

        self::assertNull($tokens->peek($first, AccountTokenType::PasswordReset));
        self::assertNotNull($tokens->peek($second, AccountTokenType::PasswordReset));
    }
}
