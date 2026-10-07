<?php

declare(strict_types=1);

namespace App\Tests\Integration\Slug;

use App\Entity\StorySlugHistory;
use App\Service\Slug\SlugService;
use App\Tests\Integration\IntegrationTestCase;

final class SlugServiceTest extends IntegrationTestCase
{
    public function testSlugifiesHindiTitles(): void
    {
        $slugs = $this->service(SlugService::class);

        self::assertSame('shri-krishna-ki-bal-lila', $slugs->slugify('श्री कृष्ण की बाल लीला'));
        // Final "a" is kept after a conjunct (dharma, mantra, artha) — see DevanagariTransliterator.
        self::assertSame('hanuman-chalisa-ka-artha', $slugs->slugify('  हनुमान चालीसा — का अर्थ!! '));
        self::assertMatchesRegularExpression('/^item-[a-z0-9]+$/', $slugs->slugify('!!!'));
    }

    public function testLongTitlesAreCutAtAWordBoundary(): void
    {
        $slug = $this->service(SlugService::class)->slugify(str_repeat('कथा सागर ', 40));

        self::assertLessThanOrEqual(SlugService::MAX_LENGTH, \strlen($slug));
        self::assertStringEndsNotWith('-', $slug);
        self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug);
    }

    public function testStorySlugsAreUnique(): void
    {
        $slugs = $this->service(SlugService::class);
        $existing = $this->story('makhan-chor-bal-krishna');

        self::assertSame('makhan-chor-bal-krishna-2', $slugs->uniqueStorySlug('Makhan Chor Bal Krishna'));
        self::assertSame('makhan-chor-bal-krishna', $slugs->uniqueStorySlug('Makhan Chor Bal Krishna', $existing), 'A story keeps its own slug.');
    }

    public function testOldSlugsStayReservedForTheirRedirects(): void
    {
        $story = $this->story('shabri-ki-pratiksha');
        $this->em->persist(new StorySlugHistory($story, 'purani-shabri-katha'));
        $this->em->flush();

        $slugs = $this->service(SlugService::class);
        self::assertSame('purani-shabri-katha-2', $slugs->uniqueStorySlug('Purani Shabri Katha'));
        self::assertSame('purani-shabri-katha', $slugs->uniqueStorySlug('Purani Shabri Katha', $story), 'The owner may take its old slug back.');
    }
}
