<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Security\Role;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Admins manage regular accounts; only super admins change roles or touch other admins.
 * Nobody can deactivate or demote themselves (prevents accidental lock-out).
 *
 * @extends Voter<string, User>
 */
final class UserManagementVoter extends Voter
{
    public const MANAGE = 'USER_MANAGE';
    public const CHANGE_ROLES = 'USER_CHANGE_ROLES';

    public function __construct(private readonly RoleHierarchyInterface $roleHierarchy)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof User && \in_array($attribute, [self::MANAGE, self::CHANGE_ROLES], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actor = $token->getUser();
        if (!$actor instanceof User || !$actor->isActive()) {
            return false;
        }
        /** @var User $target */
        $target = $subject;
        $actorRoles = $this->roleHierarchy->getReachableRoleNames($token->getRoleNames());
        $targetRoles = $this->roleHierarchy->getReachableRoleNames($target->getRoles());
        $isSuperAdmin = \in_array(Role::SUPER_ADMIN, $actorRoles, true);
        $isSelf = $actor->getId() === $target->getId();

        return match ($attribute) {
            self::MANAGE => !$isSelf && \in_array(Role::ADMIN, $actorRoles, true) && ($isSuperAdmin || !\in_array(Role::ADMIN, $targetRoles, true)),
            self::CHANGE_ROLES => !$isSelf && $isSuperAdmin,
            default => false,
        };
    }
}
