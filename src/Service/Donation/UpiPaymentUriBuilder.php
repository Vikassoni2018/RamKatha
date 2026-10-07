<?php

declare(strict_types=1);

namespace App\Service\Donation;

/**
 * Builds NPCI UPI deep links: upi://pay?pa=...&pn=...&am=...&cu=INR&tn=...
 *
 * A UPI intent only INITIATES a payment inside the payer's UPI app; it does not prove that a
 * payment happened. The "tr" (merchant transaction ref) parameter is intentionally omitted because
 * it is reserved for merchant VPAs and makes some apps reject payments to personal VPAs.
 */
final class UpiPaymentUriBuilder
{
    public const MAX_NOTE_LENGTH = 50;

    public function __construct(private readonly MoneyFormatter $money)
    {
    }

    public function build(string $payeeVpa, string $payeeName, ?int $amountPaise, string $note = '', string $currency = 'INR'): string
    {
        if (!UpiConfiguration::isValidVpa($payeeVpa)) {
            throw new \InvalidArgumentException('Invalid UPI ID.');
        }
        if (null !== $amountPaise && $amountPaise <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        $params = [
            'pa' => $payeeVpa,
            'pn' => $this->sanitizeName($payeeName),
        ];
        if (null !== $amountPaise) {
            $params['am'] = $this->money->toDecimalString($amountPaise);
        }
        $params['cu'] = $currency;
        $note = $this->sanitizeNote($note);
        if ('' !== $note) {
            $params['tn'] = $note;
        }

        // RFC 3986 encoding (spaces → %20, "@" kept readable is not required: encode everything).
        return 'upi://pay?'.http_build_query($params, '', '&', \PHP_QUERY_RFC3986);
    }

    /** Payee names are shown in the UPI app; keep letters, digits, spaces and basic punctuation. */
    public function sanitizeName(string $name): string
    {
        $name = (string) preg_replace('/[^\p{L}\p{M}\p{N} .&\-]/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return mb_substr('' !== $name ? $name : 'Donation', 0, 50);
    }

    /** Transaction notes are restricted to ASCII letters/digits/space/-/. for broad app support. */
    public function sanitizeNote(string $note): string
    {
        $note = (string) preg_replace('/[^A-Za-z0-9 .\-]/', '', $note);
        $note = trim((string) preg_replace('/\s+/', ' ', $note));

        return substr($note, 0, self::MAX_NOTE_LENGTH);
    }
}
