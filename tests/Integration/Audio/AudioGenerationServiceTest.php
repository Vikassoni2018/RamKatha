<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audio;

use App\Entity\AudioAsset;
use App\Enum\AudioStatus;
use App\Exception\FeatureNotConfiguredException;
use App\Service\Audio\AudioGenerationService;
use App\Service\Audio\AudioRequestService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * The test environment runs with TTS_PROVIDER=none: generation must fail loudly with an
 * admin-readable reason, never produce fake audio or a fake "ready" state.
 */
final class AudioGenerationServiceTest extends IntegrationTestCase
{
    public function testRequestingAudioWithoutTtsReportsFeatureNotConfigured(): void
    {
        try {
            $this->service(AudioRequestService::class)->request($this->story('hanuman-ji-ki-lanka-yatra'), $this->user('editor'));
            self::fail('Expected FeatureNotConfiguredException.');
        } catch (FeatureNotConfiguredException $e) {
            self::assertStringStartsWith('Feature is not configured', $e->getMessage());
        }
    }

    public function testQueuedJobFailsWithReasonInsteadOfFakingSuccess(): void
    {
        $story = $this->story('hanuman-ji-ki-lanka-yatra');
        self::assertFalse($story->hasPlayableAudio());
        $asset = $this->queue($story);

        self::assertFalse($this->service(AudioGenerationService::class)->generate($asset));

        $this->em->refresh($asset);
        self::assertSame(AudioStatus::Failed, $asset->getStatus());
        self::assertStringStartsWith('Feature is not configured', (string) $asset->getErrorMessage());
        self::assertNull($asset->getStorageKey());
        self::assertSame(AudioStatus::Failed, $story->getAudioStatus());
    }

    public function testFailedRegenerationKeepsExistingAudioPlayable(): void
    {
        $story = $this->story('makhan-chor-bal-krishna');
        $current = $story->getCurrentAudio();
        self::assertTrue($story->hasPlayableAudio());

        self::assertFalse($this->service(AudioGenerationService::class)->generate($this->queue($story)));

        $this->em->refresh($story);
        self::assertSame(AudioStatus::Ready, $story->getAudioStatus(), 'The public player must keep the previous narration.');
        self::assertSame($current?->getId(), $story->getCurrentAudio()?->getId());
    }

    public function testReadyAssetsAreNotRegenerated(): void
    {
        $asset = $this->story('makhan-chor-bal-krishna')->getCurrentAudio();
        self::assertNotNull($asset);

        self::assertFalse($this->service(AudioGenerationService::class)->generate($asset));
        self::assertSame(AudioStatus::Ready, $asset->getStatus());
    }

    private function queue(\App\Entity\Story $story): AudioAsset
    {
        $asset = new AudioAsset($story, 'hi', 'none', null);
        $asset->markQueued();
        $this->em->persist($asset);
        $this->em->flush();

        return $asset;
    }
}
