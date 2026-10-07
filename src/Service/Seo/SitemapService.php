<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Repository\PageRepository;
use App\Service\I18n\Locales;
use App\Repository\StoryRepository;
use App\Service\Cache\ContentCacheInvalidator;
use App\Service\Story\TaxonomyRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * XML sitemaps (sitemaps.org protocol): an index plus stories, taxonomies and pages.
 * Story sitemaps are split into files of STORIES_PER_FILE stories (Hindi URL plus English URL when
 * translated, linked by hreflang). Output is cached (tag-invalidated).
 */
final class SitemapService
{
    public const STORIES_PER_FILE = 5000;
    private const TAXONOMIES = ['category', 'deity', 'scripture', 'series', 'festival'];

    public function __construct(
        private readonly StoryRepository $stories,
        private readonly PageRepository $pages,
        private readonly TaxonomyRegistry $taxonomies,
        private readonly SiteUrlGenerator $urls,
        #[Autowire(service: 'cache.content')]
        private readonly TagAwareCacheInterface $cache,
    ) {
    }

    public function index(): string
    {
        return $this->cached('sitemap_index', function (): string {
            $files = max(1, (int) ceil($this->stories->countPublished() / self::STORIES_PER_FILE));
            $xml = $this->writer();
            $xml->startElement('sitemapindex');
            $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
            for ($i = 1; $i <= $files; ++$i) {
                $this->sitemapEntry($xml, $this->urls->route('app_sitemap_stories', ['page' => $i]), $now);
            }
            $this->sitemapEntry($xml, $this->urls->route('app_sitemap_taxonomies'), $now);
            $this->sitemapEntry($xml, $this->urls->route('app_sitemap_pages'), $now);
            $xml->endElement();

            return $xml->outputMemory();
        });
    }

    public function stories(int $page): ?string
    {
        if ($page < 1) {
            return null;
        }

        return $this->cached('sitemap_stories_'.$page, function () use ($page): string {
            $xml = $this->urlset();
            $offset = ($page - 1) * self::STORIES_PER_FILE;
            $i = 0;
            foreach ($this->stories->iterateForSitemap() as $row) {
                if ($i++ < $offset) {
                    continue;
                }
                if ($i > $offset + self::STORIES_PER_FILE) {
                    break;
                }
                // English URLs are listed only for translated stories (others just repeat the Hindi text).
                $this->localizedEntries($xml, 'app_story_show', ['slug' => $row['slug']], $row['updatedAt'], (bool) $row['hasEnglish']);
            }
            $xml->endElement();

            return $xml->outputMemory();
        });
    }

    public function taxonomies(): string
    {
        return $this->cached('sitemap_taxonomies', function (): string {
            $xml = $this->urlset();
            $this->localizedEntries($xml, 'app_story_index', [], null);
            foreach (self::TAXONOMIES as $key) {
                $segment = $this->taxonomies->segment($key);
                $this->localizedEntries($xml, 'app_taxonomy_index', ['segment' => $segment], null);
                foreach ($this->taxonomies->repository($key)->findForNavigation() as $term) {
                    if ($term['total'] > 0) {
                        $this->localizedEntries($xml, 'app_taxonomy_show', ['segment' => $segment, 'slug' => $term['slug']], new \DateTimeImmutable($term['updatedAt']));
                    }
                }
            }
            $xml->endElement();

            return $xml->outputMemory();
        });
    }

    public function pages(): string
    {
        return $this->cached('sitemap_pages', function (): string {
            $xml = $this->urlset();
            $this->localizedEntries($xml, 'app_home', [], null);
            $this->localizedEntries($xml, 'app_support', [], null);
            $this->localizedEntries($xml, 'app_contact', [], null);
            foreach ($this->pages->findForSitemap() as $row) {
                $this->localizedEntries($xml, 'app_page', ['slug' => $row['slug']], $row['updatedAt'], (bool) $row['hasEnglish']);
            }
            $xml->endElement();

            return $xml->outputMemory();
        });
    }

    /** @param callable(): string $build */
    private function cached(string $key, callable $build): string
    {
        return $this->cache->get($key.'_i18n', function (ItemInterface $item) use ($build): string {
            $item->tag([ContentCacheInvalidator::TAG_STORIES, ContentCacheInvalidator::TAG_TAXONOMY, ContentCacheInvalidator::TAG_PAGES]);
            $item->expiresAfter(3600);

            return $build();
        });
    }

    private function writer(): \XMLWriter
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');

        return $xml;
    }

    private function urlset(): \XMLWriter
    {
        $xml = $this->writer();
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');

        return $xml;
    }

    /**
     * The Hindi URL and, when the page exists in English, the English URL — each annotated with
     * hreflang alternates (x-default = Hindi), as recommended for multilingual sitemaps.
     *
     * @param array<string, mixed> $params
     */
    private function localizedEntries(\XMLWriter $xml, string $route, array $params, ?\DateTimeInterface $lastmod, bool $hasEnglish = true): void
    {
        $alternates = $hasEnglish ? $this->urls->alternates($route, $params) : [Locales::DEFAULT => $this->urls->route($route, $params, Locales::DEFAULT)];
        foreach ($alternates as $loc) {
            $this->urlEntry($xml, $loc, $lastmod, \count($alternates) > 1 ? $alternates : []);
        }
    }

    /** @param array<string, string> $alternates locale => URL */
    private function urlEntry(\XMLWriter $xml, string $loc, ?\DateTimeInterface $lastmod, array $alternates = []): void
    {
        $xml->startElement('url');
        $xml->writeElement('loc', $loc);
        if (null !== $lastmod) {
            $xml->writeElement('lastmod', $lastmod->format(\DATE_ATOM));
        }
        foreach ($alternates + ['x-default' => $alternates[Locales::DEFAULT] ?? null] as $hreflang => $href) {
            if (null === $href) {
                continue;
            }
            $xml->startElement('xhtml:link');
            $xml->writeAttribute('rel', 'alternate');
            $xml->writeAttribute('hreflang', $hreflang);
            $xml->writeAttribute('href', $href);
            $xml->endElement();
        }
        $xml->endElement();
    }

    private function sitemapEntry(\XMLWriter $xml, string $loc, string $lastmod): void
    {
        $xml->startElement('sitemap');
        $xml->writeElement('loc', $loc);
        $xml->writeElement('lastmod', $lastmod);
        $xml->endElement();
    }
}
