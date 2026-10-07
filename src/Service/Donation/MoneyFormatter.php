<?php

declare(strict_types=1);

namespace App\Service\Donation;

/**
 * Money is always handled as integer paise. This formatter is the only place that converts
 * paise to display strings: ₹51, ₹101, ₹1,25,000.50 (Indian digit grouping).
 */
final class MoneyFormatter
{
    public function format(int $paise, string $currency = 'INR', bool $forceDecimals = false): string
    {
        $negative = $paise < 0;
        $paise = abs($paise);
        $rupees = intdiv($paise, 100);
        $fraction = $paise % 100;

        $formatted = $this->groupIndian((string) $rupees);
        if ($fraction > 0 || $forceDecimals) {
            $formatted .= '.'.str_pad((string) $fraction, 2, '0', \STR_PAD_LEFT);
        }
        $symbol = 'INR' === $currency ? '₹' : $currency.' ';

        return ($negative ? '-' : '').$symbol.$formatted;
    }

    /** "101.00" style decimal string for protocols (UPI "am" parameter, CSV exports). */
    public function toDecimalString(int $paise): string
    {
        return \sprintf('%d.%02d', intdiv($paise, 100), $paise % 100);
    }

    /**
     * Parses user input like "101", "101.5", "1,001.50" into paise without floating point.
     */
    public function parseToPaise(string $input): ?int
    {
        $input = str_replace([',', ' ', '₹'], '', trim($input));
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $input, $m)) {
            return null;
        }
        $fraction = isset($m[2]) ? str_pad($m[2], 2, '0') : '00';

        return (int) $m[1] * 100 + (int) $fraction;
    }

    /** 1234567 → "12,34,567" */
    private function groupIndian(string $digits): string
    {
        if (\strlen($digits) <= 3) {
            return $digits;
        }
        $last3 = substr($digits, -3);
        $rest = substr($digits, 0, -3);
        $rest = (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);

        return $rest.','.$last3;
    }
}
