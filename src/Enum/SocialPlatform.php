<?php

declare(strict_types=1);

namespace App\Enum;

enum SocialPlatform: string
{
    case Facebook = 'facebook';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook Page',
        };
    }
}
