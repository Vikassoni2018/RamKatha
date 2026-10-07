<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when an operation needs an integration that is not configured (TTS, FFmpeg, Facebook,
 * UPI, mail...). Controllers turn it into a clear "Feature is not configured" message instead of
 * pretending the operation succeeded.
 */
final class FeatureNotConfiguredException extends \RuntimeException
{
    public function __construct(public readonly string $feature, string $message)
    {
        parent::__construct($message);
    }
}
