<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\Story;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\VerificationStatus;
use App\Exception\DomainRuleException;
use App\Service\Audit\AuditLogger;
use Symfony\Component\Clock\ClockInterface;

/**
 * Source verification. The "Source Verified" badge is shown only after an authorized reviewer
 * explicitly verifies; any later change to content or sources resets it automatically.
 */
final class StoryVerificationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    public function verify(Story $story, User $reviewer, ?string $notes): void
    {
        if ($story->getSourceType()->requiresReference() && $story->getSourceReferences()->isEmpty()) {
            throw new DomainRuleException(\sprintf('"%s" requires at least one source reference before it can be verified.', $story->getSourceType()->label()));
        }

        $now = $this->clock->now();
        $story->markSourceVerified($reviewer, $now, $notes);
        $story->recordReview($reviewer, $now, $notes);
        $this->audit->log(AuditAction::StorySourceVerified, $story, ['notes' => $notes, 'sourceType' => $story->getSourceType()]);
    }

    public function flagNeedsCorrection(Story $story, User $reviewer, ?string $notes): void
    {
        $story->resetSourceVerification(VerificationStatus::NeedsCorrection, $notes);
        $story->recordReview($reviewer, $this->clock->now(), $notes);
        $this->audit->log(AuditAction::StoryVerificationReset, $story, ['reason' => 'needs_correction', 'notes' => $notes]);
    }

    /** Called when verified content changed. */
    public function resetAfterChange(Story $story, array $changedFields): void
    {
        if (VerificationStatus::Verified !== $story->getVerificationStatus()) {
            return;
        }
        $story->resetSourceVerification(VerificationStatus::Unverified, 'Content or sources changed after verification — re-verification required.');
        $this->audit->log(AuditAction::StoryVerificationReset, $story, ['reason' => 'content_changed', 'fields' => $changedFields]);
    }
}
