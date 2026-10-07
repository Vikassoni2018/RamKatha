<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Content-Security-Policy for HTML pages. Scripts may only come from this origin or carry the
 * per-request nonce (used by the inline import map that AssetMapper renders). Inline styles stay
 * allowed: they are needed by Turbo's progress bar and carry far less risk than scripts.
 */
final class ContentSecurityPolicy implements ResetInterface
{
    private ?string $nonce = null;

    public function nonce(): string
    {
        return $this->nonce ??= bin2hex(random_bytes(16));
    }

    public function headerValue(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-".$this->nonce()."'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "media-src 'self' blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }

    public function reset(): void
    {
        $this->nonce = null;
    }
}
