<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Role names used across the application (avoid magic strings).
 * The hierarchy itself is configured in config/packages/security.yaml.
 */
final class Role
{
    public const USER = 'ROLE_USER';
    public const STAFF = 'ROLE_STAFF';
    public const AUTHOR = 'ROLE_AUTHOR';
    public const REVIEWER = 'ROLE_REVIEWER';
    public const EDITOR = 'ROLE_EDITOR';
    public const ADMIN = 'ROLE_ADMIN';
    public const SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    /** Roles that can be assigned through the admin UI, with descriptions. */
    public const ASSIGNABLE = [
        self::AUTHOR => 'Author — create and edit own draft stories',
        self::REVIEWER => 'Reviewer — review and verify sources',
        self::EDITOR => 'Editor — edit all content, taxonomy, audio, publishing',
        self::ADMIN => 'Admin — users, donations, social, analytics',
        self::SUPER_ADMIN => 'Super admin — roles, settings, system',
    ];

    private function __construct()
    {
    }

    public static function label(string $role): string
    {
        return match ($role) {
            self::USER => 'User',
            self::AUTHOR => 'Author',
            self::REVIEWER => 'Reviewer',
            self::EDITOR => 'Editor',
            self::ADMIN => 'Admin',
            self::SUPER_ADMIN => 'Super admin',
            default => $role,
        };
    }
}
