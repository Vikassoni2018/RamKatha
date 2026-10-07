<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\StorySlugHistory;

final class PublicPagesTest extends AppWebTestCase
{
    public function testHomePageShowsOnlyPublishedStories(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('href="/katha/makhan-chor-bal-krishna"', $html);
        self::assertStringNotContainsString('/katha/ganesh-ji-aur-chandrama', $html, 'Drafts are never listed.');
        self::assertStringNotContainsString('/katha/ram-navmi-shriram-ka-prakatya', $html, 'Scheduled stories are not public yet.');
    }

    public function testStoryPageHasStructuredDataAndCanonical(): void
    {
        $crawler = $this->client->request('GET', '/katha/makhan-chor-bal-krishna');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'माखन चोर');
        self::assertSelectorExists('link[rel="canonical"][href="http://localhost/katha/makhan-chor-bal-krishna"]');
        self::assertSelectorExists('meta[property="og:title"]');

        $jsonLd = json_decode($crawler->filter('script[type="application/ld+json"]')->first()->text(), true, 512, \JSON_THROW_ON_ERROR);
        $types = array_column($jsonLd['@graph'], '@type');
        self::assertContains('Article', $types);
        self::assertContains('BreadcrumbList', $types);
    }

    public function testSourceVerifiedBadgeOnlyForVerifiedStories(): void
    {
        $this->client->request('GET', '/katha/makhan-chor-bal-krishna');
        self::assertSelectorTextContains('.story-header .badge-verified', 'स्रोत सत्यापित');

        // Published, but verification was reset after an edit: no badge.
        $this->client->request('GET', '/katha/nilkanth-mahadev-aur-samudra-manthan');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.story-header .badge-verified');
    }

    public function testDemoContentIsClearlyLabelled(): void
    {
        $this->client->request('GET', '/katha/makhan-chor-bal-krishna');

        self::assertSelectorTextContains('.story-header .badge-demo', 'Demo Content');
    }

    public function testUnpublishedStoriesAreNotFound(): void
    {
        foreach (['ganesh-ji-aur-chandrama', 'navratri-aur-devi-ke-nau-rup', 'ram-navmi-shriram-ka-prakatya', 'no-such-story'] as $slug) {
            $this->client->request('GET', '/katha/'.$slug);
            self::assertResponseStatusCodeSame(404, $slug);
        }
    }

    public function testOldSlugRedirectsPermanently(): void
    {
        $em = $this->em();
        $em->persist(new StorySlugHistory($this->story('shabri-ki-pratiksha'), 'shabri-ki-purani-katha'));
        $em->flush();

        $this->client->request('GET', '/katha/shabri-ki-purani-katha');

        self::assertResponseRedirects('/katha/shabri-ki-pratiksha', 301);
    }

    public function testSitemapsListOnlyPublishedContent(): void
    {
        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        $index = simplexml_load_string((string) $this->client->getResponse()->getContent());
        self::assertNotFalse($index);
        self::assertStringContainsString('http://localhost/sitemap/stories-1.xml', $index->asXML());

        $this->client->request('GET', '/sitemap/stories-1.xml');
        self::assertResponseIsSuccessful();
        $xml = (string) $this->client->getResponse()->getContent();
        self::assertNotFalse(simplexml_load_string($xml));
        self::assertStringContainsString('<loc>http://localhost/katha/makhan-chor-bal-krishna</loc>', $xml);
        self::assertStringNotContainsString('ganesh-ji-aur-chandrama', $xml);
        self::assertStringNotContainsString('ram-navmi-shriram-ka-prakatya', $xml);
    }

    public function testRobotsTxt(): void
    {
        $this->client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Sitemap: http://localhost/sitemap.xml', $content);
        self::assertStringContainsString('Disallow: /admin', $content);
    }

    public function testFullTextSearchFindsHindiWords(): void
    {
        $this->client->request('GET', '/search', ['q' => 'गोवर्धन']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/katha/shrikrishna-aur-govardhan-parvat"]');
    }

    public function testSearchNeverExposesDrafts(): void
    {
        $this->client->request('GET', '/search', ['q' => 'गणेश चंद्रमा']);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/katha/ganesh-ji-aur-chandrama"]');
    }

    public function testSearchHandlesHostileInput(): void
    {
        foreach (['"><script>alert(1)</script>', '+-*()~@<>"', str_repeat('क', 500), "' OR 1=1 --"] as $query) {
            $this->client->request('GET', '/search', ['q' => $query]);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('<script>alert(1)</script>', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testListingAndTaxonomyPagesRender(): void
    {
        foreach (['/katha', '/category', '/granth', '/collection', '/support', '/contact', '/login', '/register'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    public function testUnknownUrlsReturn404(): void
    {
        $this->client->request('GET', '/is-page-ka-koi-astitva-nahin');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSecurityHeadersAndScriptNonce(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('X-Frame-Options', 'SAMEORIGIN');
        self::assertResponseHeaderSame('Referrer-Policy', 'strict-origin-when-cross-origin');
        $csp = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-([a-f0-9]{32})'/", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);

        preg_match("/'nonce-([a-f0-9]{32})'/", $csp, $m);
        // JSON data blocks (JSON-LD, UI strings) are never executed, so they need no nonce.
        $scripts = $crawler->filter('script:not([type="application/ld+json"]):not([type="application/json"])');
        self::assertGreaterThan(0, $scripts->count());
        foreach ($scripts as $script) {
            self::assertSame($m[1], $script->getAttribute('nonce'), 'Every executable inline script carries the request nonce.');
        }
    }

    public function testSupportPageIsVoluntaryAndAsksForNoCredentials(): void
    {
        $crawler = $this->client->request('GET', '/support');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('ऐच्छिक', $text);
        self::assertStringContainsString('गारंटी नहीं', $text, 'The page must state that support guarantees no outcome.');
        self::assertCount(0, $crawler->filter('input[name*="pin"], input[name*="PIN"], input[name*="otp"], input[name*="OTP"], input[type="password"]'));
    }
}
