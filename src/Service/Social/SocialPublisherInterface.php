<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialPost;
use App\Enum\SocialPlatform;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** A social platform adapter. Only Facebook is implemented; others can be added as new adapters. */
#[AutoconfigureTag('app.social_publisher')]
interface SocialPublisherInterface
{
    public function getPlatform(): SocialPlatform;

    public function getConfigurationError(): ?string;

    /** Never throws for remote errors: failures are returned as PublishResult::failure(). */
    public function publish(SocialPost $post): PublishResult;

    /** Verifies credentials (e.g. fetch the page name). */
    public function testConnection(): PublishResult;
}
