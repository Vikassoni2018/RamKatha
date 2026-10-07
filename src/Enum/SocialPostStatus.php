<?php

declare(strict_types=1);

namespace App\Enum;

enum SocialPostStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Queued => 'Queued',
            self::Publishing => 'Publishing',
            self::Published => 'Published',
            self::Failed => 'Failed',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Queued => 'info',
            self::Publishing => 'warning',
            self::Published => 'success',
            self::Failed => 'danger',
        };
    }

    public function isEditable(): bool
    {
        return \in_array($this, [self::Draft, self::Queued, self::Failed], true);
    }
}
