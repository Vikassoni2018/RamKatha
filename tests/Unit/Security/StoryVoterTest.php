<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Story;
use App\Entity\User;
use App\Enum\StoryStatus;
use App\Enum\StoryTransition;
use App\Security\Role;
use App\Security\Voter\StoryVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

final class StoryVoterTest extends TestCase
{
    private StoryVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new StoryVoter(new RoleHierarchy([
            Role::AUTHOR => [Role::USER, Role::STAFF],
            Role::REVIEWER => [Role::USER, Role::STAFF],
            Role::EDITOR => [Role::AUTHOR],
            Role::ADMIN => [Role::EDITOR, Role::REVIEWER],
            Role::SUPER_ADMIN => [Role::ADMIN],
        ]));
    }

    private function user(int $id, array $roles): User
    {
        $user = (new User())->setEmail('u'.$id.'@example.test')->setRoles($roles);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function vote(User $user, string $attribute, ?Story $story): int
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->voter->vote($token, $story, [$attribute]);
    }

    private function story(User $owner, StoryStatus $status): Story
    {
        return (new Story())->setCreatedBy($owner)->setStatus($status);
    }

    public function testAuthorCanEditOnlyOwnDrafts(): void
    {
        $author = $this->user(1, [Role::AUTHOR]);
        $other = $this->user(2, [Role::AUTHOR]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($author, StoryVoter::EDIT, $this->story($author, StoryStatus::Draft)));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($author, StoryVoter::EDIT, $this->story($author, StoryStatus::UnderReview)));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($author, StoryVoter::EDIT, $this->story($other, StoryStatus::Draft)));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($author, StoryVoter::PREVIEW, $this->story($other, StoryStatus::Draft)));
    }

    public function testOnlyReviewersVerifySources(): void
    {
        $author = $this->user(1, [Role::AUTHOR]);
        $editor = $this->user(3, [Role::EDITOR]);
        $reviewer = $this->user(4, [Role::REVIEWER]);
        $story = $this->story($author, StoryStatus::UnderReview);
        $verify = StoryVoter::transitionAttribute(StoryTransition::VerifySource);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($author, $verify, $story));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($editor, $verify, $story), 'Editors do not verify sources (separation of duties).');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($reviewer, $verify, $story));
    }

    public function testPublishingRequiresEditor(): void
    {
        $author = $this->user(1, [Role::AUTHOR]);
        $reviewer = $this->user(4, [Role::REVIEWER]);
        $editor = $this->user(3, [Role::EDITOR]);
        $story = $this->story($author, StoryStatus::Approved);
        $publish = StoryVoter::transitionAttribute(StoryTransition::Publish);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($author, $publish, $story));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($reviewer, $publish, $story));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($editor, $publish, $story));
    }

    public function testArchiveRequiresAdminAndDeleteOnlyNeverPublishedDrafts(): void
    {
        $editor = $this->user(3, [Role::EDITOR]);
        $admin = $this->user(5, [Role::ADMIN]);
        $published = $this->story($editor, StoryStatus::Published)->setPublishedAt(new \DateTimeImmutable('-1 day'));
        $draft = $this->story($editor, StoryStatus::Draft);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($editor, StoryVoter::transitionAttribute(StoryTransition::Archive), $published));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, StoryVoter::transitionAttribute(StoryTransition::Archive), $published));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, StoryVoter::DELETE, $draft));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($admin, StoryVoter::DELETE, $published), 'Published stories must be archived, not deleted.');
    }

    public function testSystemTransitionIsNeverGrantedToUsers(): void
    {
        $superAdmin = $this->user(6, [Role::SUPER_ADMIN]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($superAdmin, StoryVoter::transitionAttribute(StoryTransition::MarkAudioGenerated), $this->story($superAdmin, StoryStatus::AudioPending)));
    }

    public function testDeactivatedStaffIsDenied(): void
    {
        $editor = $this->user(3, [Role::EDITOR]);
        $editor->deactivate();
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($editor, StoryVoter::EDIT, $this->story($editor, StoryStatus::Draft)));
    }

    public function testReadersHaveNoStaffPermissions(): void
    {
        $reader = $this->user(7, []);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($reader, StoryVoter::CREATE, null));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($reader, StoryVoter::PREVIEW, $this->story($reader, StoryStatus::Draft)));
    }
}
