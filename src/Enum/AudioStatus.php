<?php

declare(strict_types=1);

namespace App\Enum;

enum AudioStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
            self::Disabled => 'Disabled',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending, self::Queued => 'neutral',
            self::Processing => 'info',
            self::Ready => 'success',
            self::Failed => 'danger',
            self::Disabled => 'muted',
        };
    }

    public function isInProgress(): bool
    {
        return \in_array($this, [self::Pending, self::Queued, self::Processing], true);
    }
}
