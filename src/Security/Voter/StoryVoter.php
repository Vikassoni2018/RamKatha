<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Story;
use App\Entity\User;
use App\Enum\StoryStatus;
use App\Enum\StoryTransition;
use App\Security\Role;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Permission matrix for stories (ownership + editorial workflow):
 *
 *  Author   : create; view/edit/preview OWN stories while in draft; submit own draft for review
 *  Reviewer : view/preview all; verify sources; send back to draft
 *  Editor   : edit any non-archived story; generate audio; approve, schedule, publish, unpublish
 *  Admin    : everything above + archive/restore + social publishing
 *  Super admin: everything
 *
 * @extends Voter<string, Story|null>
 */
final class StoryVoter extends Voter
{
    public const CREATE = 'STORY_CREATE';
    public const VIEW_ADMIN = 'STORY_VIEW_ADMIN';
    public const EDIT = 'STORY_EDIT';
    public const PREVIEW = 'STORY_PREVIEW';
    public const DELETE = 'STORY_DELETE';
    public const MANAGE_AUDIO = 'STORY_MANAGE_AUDIO';
    public const SOCIAL = 'STORY_SOCIAL';

    private const TRANSITION_PREFIX = 'STORY_T_';

    public function __construct(private readonly RoleHierarchyInterface $roleHierarchy)
    {
    }

    public static function transitionAttribute(StoryTransition $transition): string
    {
        return self::TRANSITION_PREFIX.strtoupper($transition->value);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (self::CREATE === $attribute) {
            return true;
        }
        if (!$subject instanceof Story) {
            return false;
        }

        return \in_array($attribute, [self::VIEW_ADMIN, self::EDIT, self::PREVIEW, self::DELETE, self::MANAGE_AUDIO, self::SOCIAL], true)
            || str_starts_with($attribute, self::TRANSITION_PREFIX);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }
        $roles = $this->roleHierarchy->getReachableRoleNames($token->getRoleNames());
        $has = static fn (string $role): bool => \in_array($role, $roles, true);

        if (self::CREATE === $attribute) {
            return $has(Role::AUTHOR);
        }

        /** @var Story $story */
        $story = $subject;
        $isOwner = null !== $story->getCreatedBy() && $story->getCreatedBy()->getId() === $user->getId();
        $isArchived = StoryStatus::Archived === $story->getStatus();

        if (str_starts_with($attribute, self::TRANSITION_PREFIX)) {
            $transition = StoryTransition::tryFrom(strtolower(substr($attribute, \strlen(self::TRANSITION_PREFIX))));

            return null !== $transition && $this->canTransition($transition, $isOwner, $has);
        }

        return match ($attribute) {
            self::VIEW_ADMIN, self::PREVIEW => $has(Role::REVIEWER) || $has(Role::EDITOR) || ($isOwner && $has(Role::AUTHOR)),
            self::EDIT => !$isArchived && ($has(Role::EDITOR) || ($isOwner && $has(Role::AUTHOR) && $story->getStatus()->isEditableByAuthor())),
            // Hard delete only for never-published drafts; everything else must be archived.
            self::DELETE => $has(Role::ADMIN) && null === $story->getFirstPublishedAt() && \in_array($story->getStatus(), [StoryStatus::Draft, StoryStatus::Archived], true),
            self::MANAGE_AUDIO => $has(Role::EDITOR),
            self::SOCIAL => $has(Role::ADMIN),
            default => false,
        };
    }

    /** @param callable(string): bool $has */
    private function canTransition(StoryTransition $transition, bool $isOwner, callable $has): bool
    {
        return match ($transition) {
            StoryTransition::Submit => $has(Role::EDITOR) || ($isOwner && $has(Role::AUTHOR)),
            StoryTransition::RequestChanges => $has(Role::REVIEWER) || $has(Role::EDITOR),
            StoryTransition::VerifySource => $has(Role::REVIEWER),
            StoryTransition::RequestAudio, StoryTransition::Approve, StoryTransition::Schedule,
            StoryTransition::Unschedule, StoryTransition::Publish, StoryTransition::Unpublish => $has(Role::EDITOR),
            StoryTransition::Archive, StoryTransition::Restore => $has(Role::ADMIN),
            StoryTransition::MarkAudioGenerated => false, // system only
        };
    }
}
