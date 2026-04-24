<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\v1\User;
use App\Models\v1\Branch;
use App\Models\v1\Company;
use App\Models\v1\Medicine;
use App\Models\v1\Inventory;
use App\Models\v1\Permission;
use App\Models\v1\Batch;
use App\Models\v1\SuperAdmin;
use Illuminate\Support\Facades\Hash;

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
            ['permission_name' => 'claims', 'description' => 'Can access HMO and PhilHealth claims page'],
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
        $allPermissionIds = Permission::pluck('permission_id')->toArray();

        // -------------------------------------------------
        // 3. Create one company and one branch for testing
        // -------------------------------------------------
        $company = Company::updateOrCreate(
            ['company_email' => 'testcompany@kmvpharmacy.com'],
            [
                'company_name' => 'KMV Pharmacy Test Company',
                'tin_number' => '1234567890',
            ]
        );

        $branch = Branch::updateOrCreate(
            [
                'company_id' => $company->company_id,
                'branch_name' => 'KMV Main Branch',
            ],
            [
                'branch_address' => 'Testing Branch Address',
                'branch_contact' => '09123456789',
                'status' => 'active',
            ]
        );

        // -------------------------------------------------
        // 5. Create 5 medicines for the single test branch
        // 6. Create inventory per medicine for that branch
        // -------------------------------------------------
        foreach ([
            [
                'medicine_name' => 'Biogesic',
                'generic_name' => 'Paracetamol',
                'category' => 'Analgesic',
                'stocks' => 100,
                'unit' => 'Tablet',
                'dosage' => 500,
                'price' => 8.50,
                'type' => 'Tablet',
                'reorder_level' => 20,
                'is_dangerous' => false,
                'needs_protection' => false,
            ],
            [
                'medicine_name' => 'Amoxil',
                'generic_name' => 'Amoxicillin',
                'category' => 'Antibiotic',
                'stocks' => 75,
                'unit' => 'Capsule',
                'dosage' => 500,
                'price' => 18.00,
                'type' => 'Capsule',
                'reorder_level' => 15,
                'is_dangerous' => false,
                'needs_protection' => true,
            ],
            [
                'medicine_name' => 'Neozep',
                'generic_name' => 'Phenylephrine + Chlorphenamine + Paracetamol',
                'category' => 'Cold and Flu',
                'stocks' => 60,
                'unit' => 'Tablet',
                'dosage' => 500,
                'price' => 10.00,
                'type' => 'Tablet',
                'reorder_level' => 10,
                'is_dangerous' => false,
                'needs_protection' => false,
            ],
            [
                'medicine_name' => 'Benadryl',
                'generic_name' => 'Diphenhydramine',
                'category' => 'Antihistamine',
                'stocks' => 40,
                'unit' => 'mL',
                'dosage' => 60,
                'price' => 120.00,
                'type' => 'Syrup',
                'reorder_level' => 8,
                'is_dangerous' => false,
                'needs_protection' => false,
            ],
            [
                'medicine_name' => 'Losartan',
                'generic_name' => 'Losartan Potassium',
                'category' => 'Maintenance',
                'stocks' => 50,
                'unit' => 'Tablet',
                'dosage' => 50,
                'price' => 15.00,
                'type' => 'Tablet',
                'reorder_level' => 12,
                'is_dangerous' => false,
                'needs_protection' => true,
            ],
        ] as $medicineData) {
            $medicine = Medicine::updateOrCreate(
                [
                    'medicine_name' => $medicineData['medicine_name'],
                    'generic_name' => $medicineData['generic_name'],
                ],
                $medicineData
            );

            $inventoryExists = Inventory::query()
                ->where('branch_id', $branch->branch_id)
                ->where('medicine_id', $medicine->medicine_id)
                ->exists();

            if (!$inventoryExists) {
                $batch = Batch::factory()->create();

                Inventory::create([
                    'branch_id' => $branch->branch_id,
                    'medicine_id' => $medicine->medicine_id,
                    'batch_id' => $batch->batch_id,
                ]);
            }
        }

        // -------------------------------------------------
        // 6. Create the primary admin user
        // -------------------------------------------------
        $admin = SuperAdmin::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'first_name' => 'Primary',
                'last_name' => 'Admin',
                'password' => Hash::make('admin123'),
                'address' => 'System Administrator Address',
                'registered_ip' => '127.0.0.1',
                'last_seen_ip' => '127.0.0.1',
            ]
        );
    }
}
