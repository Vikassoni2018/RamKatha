<?php

declare(strict_types=1);

namespace App\Enum;

/** Generation status for derived social assets (audiograms). */
enum AssetStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::Processing => 'info',
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }
}
