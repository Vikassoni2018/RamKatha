<?php

declare(strict_types=1);

namespace App\Message;

/** Publishes a SocialPost to its platform. Idempotent: already published posts are skipped. */
final class PublishSocialPost
{
    public function __construct(
        public readonly int $socialPostId,
    ) {
    }
}
