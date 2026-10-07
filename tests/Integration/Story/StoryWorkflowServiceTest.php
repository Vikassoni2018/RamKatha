<?php

declare(strict_types=1);

namespace App\Tests\Integration\Story;

use App\Entity\Story;
use App\Enum\StoryStatus;
use App\Enum\StoryTransition;
use App\Enum\VerificationStatus;
use App\Exception\DomainRuleException;
use App\Service\Story\ScheduledPublisher;
use App\Service\Story\StoryVerificationService;
use App\Service\Story\StoryWorkflowService;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class StoryWorkflowServiceTest extends IntegrationTestCase
{
    use ClockSensitiveTrait;

    private function workflow(): StoryWorkflowService
    {
        return $this->service(StoryWorkflowService::class);
    }

    public function testEditorialPipelineFromReviewToPublication(): void
    {
        $story = $this->story('navratri-aur-devi-ke-nau-rup');
        self::assertSame(StoryStatus::UnderReview, $story->getStatus());

        $this->workflow()->apply($story, StoryTransition::VerifySource, $this->user('reviewer'), ['notes' => 'स्रोत मिलान किया।']);
        self::assertSame(StoryStatus::SourceVerified, $story->getStatus());
        self::assertTrue($story->isSourceVerified());
        self::assertSame('reviewer@example.test', $story->getSourceVerifiedBy()?->getEmail());

        $this->workflow()->apply($story, StoryTransition::Approve, $this->user('editor'));
        $this->workflow()->apply($story, StoryTransition::Publish, $this->user('editor'));

        $this->em->clear();
        $published = $this->story('navratri-aur-devi-ke-nau-rup');
        self::assertSame(StoryStatus::Published, $published->getStatus());
        self::assertNotNull($published->getPublishedAt());
        self::assertTrue($published->isPublished());
    }

    public function testRolesAreEnforced(): void
    {
        $story = $this->story('navratri-aur-devi-ke-nau-rup');

        foreach (['author', 'editor', 'user'] as $name) {
            try {
                $this->workflow()->apply($story, StoryTransition::VerifySource, $this->user($name));
                self::fail($name.' must not be able to verify sources.');
            } catch (AccessDeniedException) {
                self::assertSame(StoryStatus::UnderReview, $story->getStatus());
            }
        }
    }

    public function testInvalidTransitionsAreRefused(): void
    {
        $draft = $this->story('ganesh-ji-aur-chandrama');

        $this->expectException(DomainRuleException::class);
        $this->workflow()->apply($draft, StoryTransition::Publish, $this->user('superadmin'));
    }

    public function testUnverifiedStoriesCannotBeApproved(): void
    {
        $story = $this->story('navratri-aur-devi-ke-nau-rup');
        $story->setStatus(StoryStatus::SourceVerified); // simulate a reset after an edit
        $story->resetSourceVerification(VerificationStatus::Unverified, 'test');

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('verified');
        $this->workflow()->apply($story, StoryTransition::Approve, $this->user('editor'));
    }

    public function testContentChangesResetVerification(): void
    {
        $story = $this->story('makhan-chor-bal-krishna');
        self::assertTrue($story->isSourceVerified());

        $this->service(StoryVerificationService::class)->resetAfterChange($story, ['body']);

        self::assertFalse($story->isSourceVerified(), 'The "Source Verified" badge must disappear after an edit.');
    }

    public function testScheduledPublishingIsIdempotent(): void
    {
        $story = $this->story('ram-navmi-shriram-ka-prakatya');
        $story->setScheduledAt(new \DateTimeImmutable('+2 days'));
        $this->em->flush();
        $publisher = $this->service(ScheduledPublisher::class);

        self::assertSame([], $publisher->publishDue(), 'Nothing is due yet.');

        self::mockTime('+3 days');
        self::assertSame([$story->getTitle()], $publisher->publishDue());
        self::assertSame([], $publisher->publishDue(), 'A second run must not publish again.');

        $this->em->clear();
        $reloaded = $this->em->find(Story::class, $story->getId());
        self::assertSame(StoryStatus::Published, $reloaded?->getStatus());
        self::assertNotNull($reloaded?->getPublishedAt());
    }
}
