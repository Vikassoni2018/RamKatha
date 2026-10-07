<?php

declare(strict_types=1);

namespace App\Service\Social;

/** Outcome of a call to a social platform (publish or connection test). */
final class PublishResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $externalId,
        public readonly ?string $externalUrl,
        public readonly ?string $remoteName,
        public readonly ?int $httpStatus,
        public readonly ?string $error,
        public readonly bool $retryable,
        public readonly ?string $responseExcerpt,
        public readonly int $durationMs,
    ) {
    }

    public static function success(?string $externalId, ?string $externalUrl, ?int $httpStatus, ?string $responseExcerpt, int $durationMs, ?string $remoteName = null): self
    {
        return new self(true, $externalId, $externalUrl, $remoteName, $httpStatus, null, false, $responseExcerpt, $durationMs);
    }

    public static function failure(string $error, bool $retryable, ?int $httpStatus = null, ?string $responseExcerpt = null, int $durationMs = 0): self
    {
        return new self(false, null, null, null, $httpStatus, $error, $retryable, $responseExcerpt, $durationMs);
    }
}
