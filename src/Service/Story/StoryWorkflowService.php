<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\AudioAsset;
use App\Entity\Story;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\StoryStatus;
use App\Enum\StoryTransition;
use App\Exception\DomainRuleException;
use App\Security\Voter\StoryVoter;
use App\Service\Audio\AudioRequestService;
use App\Service\Audit\AuditLogger;
use App\Service\Cache\ContentCacheInvalidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The only entry point for story status changes. Validates the transition table
 * (StoryTransition), checks permissions through StoryVoter for the acting user, applies the
 * change in a transaction and records an audit entry. Invalid transitions are rejected.
 */
final class StoryWorkflowService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly StoryVerificationService $verification,
        private readonly StoryPublishingService $publishing,
        private readonly AudioRequestService $audioRequests,
        private readonly AuditLogger $audit,
        private readonly ContentCacheInvalidator $cache,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Whether the transition is valid from the current state and permitted for the user. */
    public function can(Story $story, StoryTransition $transition, ?User $actor): bool
    {
        if (!$transition->canApplyFrom($story->getStatus())) {
            return false;
        }

        return null === $actor || $this->security->isGrantedForUser($actor, StoryVoter::transitionAttribute($transition), $story);
    }

    /** @return list<StoryTransition> transitions offered to the user in the admin UI */
    public function availableTransitions(Story $story, User $actor): array
    {
        return array_values(array_filter(
            StoryTransition::availableFrom($story->getStatus()),
            fn (StoryTransition $t) => $this->can($story, $t, $actor),
        ));
    }

    /**
     * @param array{notes?: ?string, scheduledAt?: ?\DateTimeImmutable} $context
     *
     * @throws DomainRuleException   invalid transition or business rule violation
     * @throws AccessDeniedException the actor may not perform this transition
     */
    public function apply(Story $story, StoryTransition $transition, ?User $actor, array $context = []): void
    {
        $from = $story->getStatus();
        if (!$transition->canApplyFrom($from)) {
            throw new DomainRuleException(\sprintf('"%s" is not possible while the story is "%s".', $transition->label(), $from->label()));
        }
        if (null !== $actor && !$this->security->isGrantedForUser($actor, StoryVoter::transitionAttribute($transition), $story)) {
            throw new AccessDeniedException(\sprintf('You are not allowed to "%s" this story.', $transition->label()));
        }

        $notes = isset($context['notes']) && '' !== trim((string) $context['notes']) ? trim((string) $context['notes']) : null;
        $queuedAudio = null;

        $this->em->wrapInTransaction(function () use ($story, $transition, $actor, $context, $notes, $from, &$queuedAudio): void {
            $now = $this->clock->now();

            switch ($transition) {
                case StoryTransition::Submit:
                    $this->assertReadyForReview($story);
                    break;
                case StoryTransition::RequestChanges:
                    if (null !== $actor) {
                        $story->recordReview($actor, $now, $notes);
                    }
                    break;
                case StoryTransition::VerifySource:
                    if (null === $actor) {
                        throw new DomainRuleException('Source verification must be performed by a reviewer.');
                    }
                    $this->verification->verify($story, $actor, $notes);
                    break;
                case StoryTransition::RequestAudio:
                    $queuedAudio = $this->audioRequests->request($story, $actor, false);
                    break;
                case StoryTransition::Approve:
                    $this->assertVerified($story);
                    if (null !== $actor) {
                        $story->recordReview($actor, $now, $notes ?? $story->getReviewNotes());
                    }
                    break;
                case StoryTransition::Schedule:
                    $this->assertVerified($story);
                    $at = $context['scheduledAt'] ?? $story->getScheduledAt();
                    if (!$at instanceof \DateTimeImmutable || $at <= $now) {
                        throw new DomainRuleException('Choose a publication date and time in the future.');
                    }
                    $story->setScheduledAt($at);
                    $this->audit->log(AuditAction::StoryScheduled, $story, ['scheduledAt' => $at], actor: $actor);
                    break;
                case StoryTransition::Unschedule:
                    $story->setScheduledAt(null);
                    break;
                case StoryTransition::Publish:
                    $this->assertVerified($story);
                    $this->publishing->publish($story, $actor);
                    break;
                case StoryTransition::Unpublish:
                    $this->publishing->unpublish($story, $actor);
                    break;
                case StoryTransition::Archive:
                    $story->setArchivedAt($now);
                    $story->setScheduledAt(null);
                    $this->audit->log(AuditAction::StoryArchived, $story, [], actor: $actor);
                    break;
                case StoryTransition::Restore:
                    $story->setArchivedAt(null);
                    $story->setPublishedAt(null);
                    break;
                case StoryTransition::MarkAudioGenerated:
                    break;
            }

            $story->setStatus($transition->to());
            if (null !== $actor) {
                $story->setUpdatedBy($actor);
            }
            $this->audit->log(AuditAction::StoryTransition, $story, [
                'transition' => $transition->value,
                'from' => $from->value,
                'to' => $transition->to()->value,
                'notes' => $notes,
            ], actor: $actor);
            $this->em->flush();
        });

        // Post-commit side effects.
        if ($queuedAudio instanceof AudioAsset) {
            $this->audioRequests->dispatch($queuedAudio);
        }
        if (StoryTransition::Publish === $transition) {
            $this->publishing->afterPublish($story, $actor);
        } elseif (\in_array($transition, [StoryTransition::Unpublish, StoryTransition::Archive], true) && StoryStatus::Published === $from) {
            $this->cache->stories();
        }
    }

    private function assertReadyForReview(Story $story): void
    {
        $missing = [];
        if ('' === trim($story->getTitle())) {
            $missing[] = 'title';
        }
        if ('' === trim($story->getExcerpt())) {
            $missing[] = 'excerpt';
        }
        if ('' === trim(strip_tags($story->getBody()))) {
            $missing[] = 'body';
        }
        if ($story->getCategories()->isEmpty()) {
            $missing[] = 'at least one category';
        }
        if ($story->getSourceType()->requiresReference() && $story->getSourceReferences()->isEmpty()) {
            $missing[] = 'a source reference (required for "'.$story->getSourceType()->label().'")';
        }
        if ([] !== $missing) {
            throw new DomainRuleException('Before submitting for review, please add: '.implode(', ', $missing).'.');
        }
    }

    private function assertVerified(Story $story): void
    {
        if (!$story->isSourceVerified()) {
            throw new DomainRuleException('The source must be verified by a reviewer first (content or sources changed since the last verification?).');
        }
    }
}
