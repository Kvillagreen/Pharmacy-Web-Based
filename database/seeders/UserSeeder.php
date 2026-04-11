<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\v1\User;
use App\Models\v1\Branch;
use App\Models\v1\Batch;
use App\Models\v1\Supplier;
use App\Models\v1\Inventory;
use App\Models\v1\Permission;
use App\Models\v1\Transaction;
use App\Models\v1\TransactionItem;
use App\Models\v1\TransactionType;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Seed permissions first
        $permissions = [
            ['permission_name' => 'dashboard', 'description' => 'Can access dashboard'],
            ['permission_name' => 'sales', 'description' => 'Can access sales page'],
            ['permission_name' => 'sms', 'description' => 'Can access sms page'],
            ['permission_name' => 'inventory', 'description' => 'Can access inventory page'],
            ['permission_name' => 'fefo', 'description' => 'Can access fefo page'],
            ['permission_name' => 'drugs', 'description' => 'Can access drugs page'],
            ['permission_name' => 'delivery', 'description' => 'Can access delivery page'],
            ['permission_name' => 'reports', 'description' => 'Can access reports page'],
            ['permission_name' => 'settings', 'description' => 'Can access settings page'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['permission_name' => $permission['permission_name']],
                ['description' => $permission['description']]
            );
        }

        Branch::factory()
            ->count(5)
            ->create(['status' => 'active']);

        Branch::factory()
            ->count(5)
            ->create(['status' => 'inactive']);

        $pendingUsers = User::factory()
            ->count(5)
            ->create(['status' => 'pending']);

        $deletedUsers = User::factory()
            ->count(5)
            ->create(['status' => 'deleted']);

        $approvedUsers = User::factory()
            ->count(5)
            ->create(['status' => 'approved']);

        $rejectedUsers = User::factory()
            ->count(5)
            ->create(['status' => 'rejected']);

        Supplier::factory()
            ->count(5)
            ->create();

        Batch::factory()
            ->count(5)
            ->create();

        Inventory::factory()
            ->count(20)
            ->create();

        Transaction::factory()
            ->count(5)
            ->create();

        TransactionType::factory()
            ->count(5)
            ->create();

        TransactionItem::factory()
            ->count(5)
            ->create();

        // -------------------------------------------------
        // Assign permissions to users
        // -------------------------------------------------

        $allPermissionIds = Permission::pluck('permission_id')->toArray();

        $basicPermissionIds = Permission::whereIn('permission_name', [
            'dashboard',
        ])->pluck('permission_id')->toArray();

        $staffPermissionIds = Permission::whereIn('permission_name', [
            'dashboard',
            'sales',
            'inventory',
        ])->pluck('permission_id')->toArray();

        $managerPermissionIds = Permission::whereIn('permission_name', [
            'dashboard',
            'sales',
            'inventory',
            'fefo',
            'drugs',
            'delivery',
            'reports',
        ])->pluck('permission_id')->toArray();

        // Pending users: dashboard only
        foreach ($pendingUsers as $user) {
            $user->permissions()->syncWithoutDetaching($basicPermissionIds);
        }

        // Deleted users: no permissions
        foreach ($deletedUsers as $user) {
            $user->permissions()->detach();
        }

        // Approved users: give wider access
        foreach ($approvedUsers as $index => $user) {
            if ($index === 0) {
                // first approved user = full access
                $user->permissions()->syncWithoutDetaching($allPermissionIds);
            } else {
                $user->permissions()->syncWithoutDetaching($managerPermissionIds);
            }
        }

        // Rejected users: limited or none
        foreach ($rejectedUsers as $user) {
            $user->permissions()->syncWithoutDetaching($staffPermissionIds);
        }
    }
}
