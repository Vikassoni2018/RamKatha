<?php

declare(strict_types=1);

namespace App\Enum;

enum NotificationLevel: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';

    public function badge(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Success => 'success',
            self::Warning => 'warning',
            self::Error => 'danger',
        };
    }
}
