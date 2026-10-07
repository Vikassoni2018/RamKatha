<?php

declare(strict_types=1);

namespace App\Tests\Unit\Story;

use App\Enum\StoryStatus;
use App\Enum\StoryTransition;
use PHPUnit\Framework\TestCase;

final class StoryTransitionTest extends TestCase
{
    public function testEditorialPipelineOrder(): void
    {
        self::assertTrue(StoryTransition::Submit->canApplyFrom(StoryStatus::Draft));
        self::assertTrue(StoryTransition::VerifySource->canApplyFrom(StoryStatus::UnderReview));
        self::assertTrue(StoryTransition::RequestAudio->canApplyFrom(StoryStatus::SourceVerified));
        self::assertTrue(StoryTransition::Approve->canApplyFrom(StoryStatus::AudioGenerated));
        self::assertTrue(StoryTransition::Schedule->canApplyFrom(StoryStatus::Approved));
        self::assertTrue(StoryTransition::Publish->canApplyFrom(StoryStatus::Scheduled));
    }

    public function testInvalidTransitionsAreRejected(): void
    {
        self::assertFalse(StoryTransition::Publish->canApplyFrom(StoryStatus::Draft), 'A draft cannot be published directly.');
        self::assertFalse(StoryTransition::Publish->canApplyFrom(StoryStatus::UnderReview));
        self::assertFalse(StoryTransition::VerifySource->canApplyFrom(StoryStatus::Draft));
        self::assertFalse(StoryTransition::Publish->canApplyFrom(StoryStatus::Published), 'Already published content is never republished.');
        self::assertFalse(StoryTransition::Schedule->canApplyFrom(StoryStatus::Draft));
        self::assertFalse(StoryTransition::Restore->canApplyFrom(StoryStatus::Published));
    }

    public function testSystemOnlyTransitionIsNeverOffered(): void
    {
        foreach (StoryStatus::cases() as $status) {
            self::assertNotContains(StoryTransition::MarkAudioGenerated, StoryTransition::availableFrom($status));
        }
    }

    public function testEveryTransitionChangesTheStatus(): void
    {
        foreach (StoryTransition::cases() as $transition) {
            self::assertNotEmpty($transition->fromStatuses());
            if (StoryTransition::RequestAudio === $transition) {
                continue; // regenerating audio legitimately stays in audio_pending
            }
            self::assertNotContains($transition->to(), $transition->fromStatuses(), $transition->value.' must change the status');
        }
    }
}
