<?php

namespace App\Services\v1;

final class RoleAccess
{
    public const USER_ROLES = ['staff', 'pharmacist', 'branch_manager', 'owner', 'admin'];

    public static function policy(string $role): array
    {
        return [
            'modules' => self::permissions($role),
            'branch_scope' => self::managesCompany($role) ? 'company' : 'assigned_branch',
            'settings_sections' => match ($role) {
                'staff', 'pharmacist' => ['Profile information', 'Security', 'Notifications'],
                'branch_manager' => ['Profile information', 'Security', 'Manage assigned branch', 'Notifications'],
                'owner', 'admin' => ['Profile information', 'Security', 'Company information', 'Manage branches', 'Notifications'],
                default => [],
            },
        ];
    }

    public static function permissions(string $role): array
    {
        return match ($role) {
            'staff', 'pharmacist' => ['sales', 'sms', 'inventory', 'fefo', 'drugs', 'settings'],
            'branch_manager' => ['dashboard', 'sales', 'sms', 'inventory', 'fefo', 'drugs', 'reports', 'settings'],
            'super_admin' => ['dashboard', 'reports', 'users', 'settings', 'branches', 'users_all_branches'],
            'admin', 'owner' => ['dashboard', 'sales', 'sms', 'inventory', 'fefo', 'drugs', 'reports', 'users', 'settings', 'branches', 'users_all_branches'],
            default => [],
        };
    }

    public static function managesCompany(string $role): bool
    {
        return in_array($role, ['admin', 'owner', 'super_admin'], true);
    }
}
