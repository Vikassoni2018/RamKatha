<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Donation;
use App\Entity\User;
use App\Enum\DonationStatus;
use App\Security\Role;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * @extends Voter<string, Donation>
 */
final class DonationVoter extends Voter
{
    /** Donor may declare payment details (capability URL with an unguessable UUID). */
    public const DECLARE = 'DONATION_DECLARE';
    public const VIEW_ADMIN = 'DONATION_VIEW_ADMIN';
    public const REVIEW = 'DONATION_REVIEW';

    public function __construct(private readonly RoleHierarchyInterface $roleHierarchy)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Donation && \in_array($attribute, [self::DECLARE, self::VIEW_ADMIN, self::REVIEW], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        /** @var Donation $donation */
        $donation = $subject;
        $user = $token->getUser();
        $isAdmin = $user instanceof User && $user->isActive()
            && \in_array(Role::ADMIN, $this->roleHierarchy->getReachableRoleNames($token->getRoleNames()), true);

        return match ($attribute) {
            self::DECLARE => \in_array($donation->getStatus(), [DonationStatus::Initiated, DonationStatus::Declared], true)
                && (null === $donation->getUser() || ($user instanceof User && $donation->getUser()->getId() === $user->getId())),
            self::VIEW_ADMIN => $isAdmin,
            self::REVIEW => $isAdmin && \in_array($donation->getStatus(), [DonationStatus::Initiated, DonationStatus::Declared, DonationStatus::Rejected, DonationStatus::Verified], true),
            default => false,
        };
    }
}
