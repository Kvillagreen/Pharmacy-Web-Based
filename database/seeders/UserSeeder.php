<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\v1\User;
use App\Models\v1\Branch;
use App\Models\v1\Company;
use App\Models\v1\Supplier;
use App\Models\v1\Medicine;
use App\Models\v1\Inventory;
use App\Models\v1\Permission;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // -------------------------------------------------
        // 1. Seed permissions
        // -------------------------------------------------
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
            ['permission_name' => 'users', 'description' => 'Can access users page'],
            ['permission_name' => 'branches', 'description' => 'Can access branches page'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['permission_name' => $permission['permission_name']],
                ['description' => $permission['description']]
            );
        }

        // -------------------------------------------------
        // 2. Permission groups
        // -------------------------------------------------
        $dashboardPermissionIds = Permission::whereIn('permission_name', [
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

        $allPermissionIds = Permission::pluck('permission_id')->toArray();

        // -------------------------------------------------
        // 3. Seed suppliers
        // -------------------------------------------------
        Supplier::factory()->count(5)->create();

        // -------------------------------------------------
        // 4. Create companies
        // -------------------------------------------------
        $companies = Company::factory()->count(3)->create();

        // -------------------------------------------------
        // 5. Create 5 branches per company
        // 6. Create 5 medicines per branch
        // 7. Create inventory per branch + medicine
        // 8. Create users per branch
        // -------------------------------------------------
        foreach ($companies as $company) {
            $branches = Branch::factory()
                ->count(5)
                ->create([
                    'company_id' => $company->company_id,
                    'status' => 'active',
                ]);

            foreach ($branches as $branch) {
                // Create 5 medicines
                $medicines = Medicine::factory()
                    ->count(5)
                    ->create();

                foreach ($medicines as $medicine) {
                    Inventory::factory()->create([
                        'branch_id' => $branch->branch_id,
                        'medicine_id' => $medicine->medicine_id,
                    ]);
                }

                // Pending users
                $pendingUsers = User::factory()
                    ->count(2)
                    ->create([
                        'branch_id' => $branch->branch_id,
                        'status' => 'pending',
                    ]);

                // Approved users
                $approvedUsers = User::factory()
                    ->count(2)
                    ->create([
                        'branch_id' => $branch->branch_id,
                        'status' => 'approved',
                    ]);

                // Rejected users
                $rejectedUsers = User::factory()
                    ->count(1)
                    ->create([
                        'branch_id' => $branch->branch_id,
                        'status' => 'rejected',
                    ]);

                // Assign permissions

                // Pending = dashboard only
                foreach ($pendingUsers as $user) {
                    $user->permissions()->syncWithoutDetaching($dashboardPermissionIds);
                }

                // Approved = first full access, rest manager access
                foreach ($approvedUsers as $index => $user) {
                    if ($index === 0) {
                        $user->permissions()->syncWithoutDetaching($allPermissionIds);
                    } else {
                        $user->permissions()->syncWithoutDetaching($managerPermissionIds);
                    }
                }

                // Rejected = dashboard only
                foreach ($rejectedUsers as $user) {
                    $user->permissions()->syncWithoutDetaching($dashboardPermissionIds);
                }
            }
        }
    }
}
