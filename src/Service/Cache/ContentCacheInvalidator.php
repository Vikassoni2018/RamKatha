<?php

declare(strict_types=1);

namespace App\Service\Cache;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Central place for cache tags of derived public content. Personalised data is never cached
 * globally (continue-listening, bookmarks, ... are per request).
 */
final class ContentCacheInvalidator
{
    public const TAG_STORIES = 'stories';
    public const TAG_TAXONOMY = 'taxonomy';
    public const TAG_PAGES = 'pages';

    public function __construct(
        #[Autowire(service: 'cache.content')]
        private readonly TagAwareCacheInterface $cache,
    ) {
    }

    public function stories(): void
    {
        $this->cache->invalidateTags([self::TAG_STORIES]);
    }

    public function taxonomy(): void
    {
        $this->cache->invalidateTags([self::TAG_TAXONOMY, self::TAG_STORIES]);
    }

    public function pages(): void
    {
        $this->cache->invalidateTags([self::TAG_PAGES]);
    }

    public function all(): void
    {
        $this->cache->invalidateTags([self::TAG_STORIES, self::TAG_TAXONOMY, self::TAG_PAGES]);
    }
}
