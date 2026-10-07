<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Bookmark;
use App\Entity\StoryProgress;
use App\Tests\Functional\AppWebTestCase;

/** Bookmarks, progress and the anonymous analytics beacon. */
final class ReaderApiTest extends AppWebTestCase
{
    private function uuid(string $slug): string
    {
        return $this->story($slug)->getUuid()->toRfc4122();
    }

    public function testBookmarkRequiresLogin(): void
    {
        $this->json('POST', '/api/bookmarks/'.$this->uuid('makhan-chor-bal-krishna'), ['bookmarked' => true]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testBookmarkRejectsCrossSiteAndTokenlessRequests(): void
    {
        $this->loginAs('user');
        $url = '/api/bookmarks/'.$this->uuid('makhan-chor-bal-krishna');

        $this->json('POST', $url, ['bookmarked' => true], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], '{"bookmarked":true}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testBookmarkSetAndUnsetIsIdempotent(): void
    {
        $user = $this->loginAs('user');
        $story = $this->story('makhan-chor-bal-krishna');
        $url = '/api/bookmarks/'.$story->getUuid()->toRfc4122();

        self::assertSame(['bookmarked' => true], $this->json('POST', $url, ['bookmarked' => true]));
        self::assertSame(['bookmarked' => true], $this->json('POST', $url, ['bookmarked' => true]));
        self::assertSame(1, $this->em()->getRepository(Bookmark::class)->count(['user' => $user->getId(), 'story' => $story->getId()]));

        self::assertSame(['bookmarked' => false], $this->json('POST', $url, ['bookmarked' => false]));
        self::assertSame(0, $this->em()->getRepository(Bookmark::class)->count(['user' => $user->getId(), 'story' => $story->getId()]));
    }

    public function testUnpublishedStoriesCannotBeBookmarked(): void
    {
        $this->loginAs('user');
        $this->json('POST', '/api/bookmarks/'.$this->uuid('ganesh-ji-aur-chandrama'), ['bookmarked' => true]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testProgressIsSavedAndClamped(): void
    {
        $user = $this->loginAs('user');
        $story = $this->story('makhan-chor-bal-krishna');
        $uuid = $story->getUuid()->toRfc4122();

        $result = $this->json('POST', '/api/progress', ['story' => $uuid, 'position' => 20, 'duration' => 60, 'reading' => 35]);
        self::assertResponseIsSuccessful();
        self::assertSame(20, $result['position']);
        self::assertFalse($result['completed']);
        self::assertSame(35, $result['reading']);

        $result = $this->json('POST', '/api/progress', ['story' => $uuid, 'position' => 99999, 'duration' => 60]);
        self::assertSame(60, $result['position'], 'Position can never exceed the duration.');
        self::assertTrue($result['completed']);

        $progress = $this->em()->getRepository(StoryProgress::class)->findOneBy(['user' => $user->getId(), 'story' => $story->getId()]);
        self::assertNotNull($progress);
        self::assertTrue($progress->isCompleted());
    }

    public function testProgressValidation(): void
    {
        $this->json('POST', '/api/progress', ['story' => $this->uuid('makhan-chor-bal-krishna'), 'position' => 5]);
        self::assertResponseStatusCodeSame(401);

        $this->loginAs('user');
        $this->json('POST', '/api/progress', ['story' => 'not-a-uuid', 'position' => 5]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnalyticsCountsOnlyAllowedEventsForPublishedStories(): void
    {
        $story = $this->story('makhan-chor-bal-krishna');
        $uuid = $story->getUuid()->toRfc4122();
        $totals = fn (): array => array_map('intval', (array) $this->em()->getConnection()->fetchAssociative(
            'SELECT COALESCE(SUM(views),0) AS views, COALESCE(SUM(audio_starts),0) AS plays, COALESCE(SUM(donations_declared),0) AS declared FROM daily_story_metric WHERE story_id = ?',
            [$story->getId()],
        ));
        $before = $totals();

        $this->client->request('POST', '/api/analytics/events', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['events' => [
            ['s' => $uuid, 'e' => 'story_view'],
            ['s' => $uuid, 'e' => 'story_view'],
            ['s' => $uuid, 'e' => 'audio_play'],
            ['s' => $uuid, 'e' => 'donation_declared'], // server-side only: must be ignored
            ['s' => $this->uuid('ganesh-ji-aur-chandrama'), 'e' => 'story_view'], // draft: ignored
            ['s' => 'garbage', 'e' => 'story_view'],
        ]]));
        self::assertResponseStatusCodeSame(204);

        $after = $totals();
        self::assertSame($before['views'] + 2, $after['views']);
        self::assertSame($before['plays'] + 1, $after['plays']);
        self::assertSame($before['declared'], $after['declared'], 'Clients cannot spoof donation metrics.');
    }

    public function testAnalyticsRejectsForeignOrigins(): void
    {
        $this->client->request('POST', '/api/analytics/events', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://evil.example'], '{"events":[]}');

        self::assertResponseStatusCodeSame(403);
    }
}
