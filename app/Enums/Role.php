<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'Super Administrator';
    case Admin = 'Administrator';
    case User = 'User';

    /**
     * Roles with full administrative privileges.
     *
     * @return array<int, string>
     */
    public static function adminRoles(): array
    {
        return [
            self::SuperAdmin->slug(),
            self::Admin->slug(),
        ];
    }

    /**
     * Hierarchy level — higher numbers indicate more privilege.
     */
    public function level(): int
    {
        return match ($this) {
            self::SuperAdmin => 100,
            self::Admin => 50,
            self::User => 10,
        };
    }

    /**
     * URL-friendly slug used as the stored role identifier.
     */
    public function slug(): string
    {
        return match ($this) {
            self::SuperAdmin => 'super-admin',
            self::Admin => 'admin',
            self::User => 'user',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full system access, including role and permission management. Bypasses all authorization checks.',
            self::Admin => 'Day-to-day administrative access to users and operational tooling.',
            self::User => 'Standard authenticated user with no administrative access.',
        };
    }
}
