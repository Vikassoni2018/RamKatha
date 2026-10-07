<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Repository\PageRepository;
use App\Service\Cache\ContentCacheInvalidator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Cached taxonomy menus (with published-story counts) and footer page links.
 * Invalidated via the "taxonomy"/"stories"/"pages" cache tags. Entries carry both languages
 * (name/nameEn, title/titleEn); templates pick one with the "localized" filter.
 */
final class NavigationService
{
    public function __construct(
        private readonly TaxonomyRegistry $taxonomies,
        private readonly PageRepository $pages,
        #[Autowire(service: 'cache.content')]
        private readonly TagAwareCacheInterface $cache,
    ) {
    }

    /**
     * @return list<array{name: string, nameEn: ?string, slug: string, total: int}>
     */
    public function terms(string $taxonomy, bool $onlyWithStories = true): array
    {
        $terms = $this->cache->get('nav_terms_i18n_'.$taxonomy, function (ItemInterface $item) use ($taxonomy): array {
            $item->tag([ContentCacheInvalidator::TAG_TAXONOMY, ContentCacheInvalidator::TAG_STORIES]);
            $item->expiresAfter(3600);

            return $this->taxonomies->repository($taxonomy)->findForNavigation();
        });

        return $onlyWithStories ? array_values(array_filter($terms, static fn (array $t) => $t['total'] > 0)) : $terms;
    }

    /** @return list<array{title: string, titleEn: ?string, slug: string}> */
    public function footerPages(): array
    {
        return $this->cache->get('nav_footer_pages_i18n', function (ItemInterface $item): array {
            $item->tag([ContentCacheInvalidator::TAG_PAGES]);
            $item->expiresAfter(3600);

            try {
                return $this->pages->findFooterLinks();
            } catch (\Throwable) {
                return [];
            }
        });
    }
}
