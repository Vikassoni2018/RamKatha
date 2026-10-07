<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentMethod: string
{
    /** Static UPI intent/QR with manual admin verification (Mode A). */
    case UpiManual = 'upi_manual';
    /** Reserved for a future payment gateway adapter (Mode B). */
    case Gateway = 'gateway';

    public function label(): string
    {
        return match ($this) {
            self::UpiManual => 'UPI (manual verification)',
            self::Gateway => 'Payment gateway',
        };
    }
}
