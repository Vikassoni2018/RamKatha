<?php

declare(strict_types=1);

namespace App\Enum;

enum AccountTokenType: string
{
    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';

    /** Lifetime of the token in seconds. */
    public function ttl(): int
    {
        return match ($this) {
            self::EmailVerification => 86400 * 2,
            self::PasswordReset => 3600,
        };
    }
}
