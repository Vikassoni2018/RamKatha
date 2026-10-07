<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Controller\AppController;
use App\Service\I18n\Locales;
use App\Service\Seo\SiteUrlGenerator;
use App\Service\Seo\SitemapService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SeoController extends AppController
{
    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'], priority: 10)]
    public function robots(SiteUrlGenerator $urls): Response
    {
        $lines = ['User-agent: *', 'Disallow: /admin', 'Disallow: /audio/'];
        // Private and utility pages, in Hindi (/account) and English (/en/account).
        foreach (Locales::PREFIXES as $prefix) {
            foreach (['/account', '/api/', '/collections', '/support/', '/login', '/register', '/search'] as $path) {
                $lines[] = 'Disallow: '.$prefix.$path;
            }
        }
        array_push($lines, 'Allow: /', '', 'Sitemap: '.$urls->route('app_sitemap_index'));

        return $this->cached(new Response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']));
    }

    #[Route('/sitemap.xml', name: 'app_sitemap_index', methods: ['GET'], priority: 10)]
    public function index(SitemapService $sitemaps): Response
    {
        return $this->xml($sitemaps->index());
    }

    #[Route('/sitemap/stories-{page}.xml', name: 'app_sitemap_stories', requirements: ['page' => '\d+'], methods: ['GET'], priority: 10)]
    public function stories(int $page, SitemapService $sitemaps): Response
    {
        return $this->xml($sitemaps->stories($page) ?? throw $this->createNotFoundException());
    }

    #[Route('/sitemap/taxonomies.xml', name: 'app_sitemap_taxonomies', methods: ['GET'], priority: 10)]
    public function taxonomies(SitemapService $sitemaps): Response
    {
        return $this->xml($sitemaps->taxonomies());
    }

    #[Route('/sitemap/pages.xml', name: 'app_sitemap_pages', methods: ['GET'], priority: 10)]
    public function pages(SitemapService $sitemaps): Response
    {
        return $this->xml($sitemaps->pages());
    }

    private function xml(string $content): Response
    {
        return $this->cached(new Response($content, 200, ['Content-Type' => 'application/xml; charset=UTF-8']));
    }

    private function cached(Response $response): Response
    {
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->setSharedMaxAge(3600);

        return $response;
    }
}
