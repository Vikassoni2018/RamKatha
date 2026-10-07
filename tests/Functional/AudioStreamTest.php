<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\StoryStatus;

final class AudioStreamTest extends AppWebTestCase
{
    private function audioUrl(string $slug): string
    {
        $asset = $this->story($slug)->getCurrentAudio() ?? throw new \LogicException('Fixture story has no audio.');

        return '/audio/'.$asset->getUuid()->toRfc4122().'.mp3';
    }

    public function testPublishedNarrationStreamsWithRangeSupport(): void
    {
        $url = $this->audioUrl('makhan-chor-bal-krishna');

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'audio/mpeg');
        self::assertResponseHeaderSame('Accept-Ranges', 'bytes');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        $size = (int) $this->client->getResponse()->headers->get('Content-Length');
        self::assertGreaterThan(100, $size);

        $this->client->request('GET', $url, [], [], ['HTTP_RANGE' => 'bytes=0-99']);
        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('Content-Range', 'bytes 0-99/'.$size);
        self::assertResponseHeaderSame('Content-Length', '100');
    }

    public function testNarrationOfUnpublishedStoryIsOnlyForStaff(): void
    {
        $story = $this->story('makhan-chor-bal-krishna');
        $url = $this->audioUrl('makhan-chor-bal-krishna');
        $story->setStatus(StoryStatus::Approved)->setPublishedAt(null);
        $this->em()->flush();

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        $this->loginAs('user');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        $this->loginAs('reviewer');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testUnknownOrMalformedAudioIs404(): void
    {
        $this->client->request('GET', '/audio/01a10c17-0000-7000-8000-000000000000.mp3');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/audio/..%2F..%2F.env.mp3');
        self::assertResponseStatusCodeSame(404);
    }
}
