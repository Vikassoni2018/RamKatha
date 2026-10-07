<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\UserCollection;
use App\Enum\CollectionVisibility;
use App\Security\Role;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * @extends Voter<string, UserCollection>
 */
final class CollectionVoter extends Voter
{
    public const VIEW = 'COLLECTION_VIEW';
    public const EDIT = 'COLLECTION_EDIT';
    public const DELETE = 'COLLECTION_DELETE';

    public function __construct(private readonly RoleHierarchyInterface $roleHierarchy)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof UserCollection && \in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        /** @var UserCollection $collection */
        $collection = $subject;
        $user = $token->getUser();
        $isOwner = $user instanceof User && $collection->isOwnedBy($user);
        $isAdmin = $user instanceof User && \in_array(Role::ADMIN, $this->roleHierarchy->getReachableRoleNames($token->getRoleNames()), true);

        return match ($attribute) {
            // Unlisted/public collections are visible to anyone with the link, but only while the owner is active.
            self::VIEW => $isOwner || $isAdmin || (CollectionVisibility::Private !== $collection->getVisibility() && $collection->getUser()->isActive()),
            self::EDIT => $isOwner,
            self::DELETE => $isOwner || $isAdmin,
            default => false,
        };
    }
}
