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
use App\Models\v1\Transaction;
use App\Models\v1\TransactionItem;
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
            ['permission_name' => 'settings', 'description' => 'Can access settings page'],
            ['permission_name' => 'users', 'description' => 'Can access users page'],
            ['permission_name' => 'branches', 'description' => 'Can access branches page'],
            ['permission_name' => 'users_all_branches', 'description' => 'Can view users across all branches in the same company'],
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
                'company_name' => 'Sto. Rosario Drug Store Test Company',
                'tin_number' => '1234567890',
            ]
        );

        $branch = Branch::updateOrCreate(
            [
                'company_id' => $company->company_id,
                'branch_name' => 'Sto. Rosario Main Branch',
            ],
            [
                'branch_address' => 'Testing Branch Address',
                'branch_contact' => '09123456789',
                'status' => 'active',
            ]
        );

        $branch2 = Branch::updateOrCreate(
            [
                'company_id' => $company->company_id,
                'branch_name' => 'Sto. Rosario Main Branch 2',
            ],
            [
                'branch_address' => 'Testing Branch Address',
                'branch_contact' => '09123456790',
                'status' => 'active',
            ]
        );

        $owner = User::updateOrCreate(
            ['email' => 'owner@gmail.com'],
            [
                'branch_id' => $branch->branch_id,
                'first_name' => 'Store',
                'last_name' => 'Owner',
                'password' => Hash::make('admin123'),
                'status' => 'approved',
                'role' => 'owner',
                'address' => 'Testing Branch Address',
                'registered_ip' => '127.0.0.1',
                'last_login_ip' => '127.0.0.1',
                'last_seen_ip' => '127.0.0.1',
            ]
        );

        $owner->permissions()->sync($allPermissionIds);

        // -------------------------------------------------
        // 5. Create 50 realistic medicines for both branches
        // 6. Create matching batches and inventory
        // -------------------------------------------------
        $medicineSamples = [
            ['Biogesic 500 mg Tablet', 'Paracetamol', 'Analgesic/Antipyretic', 5.50, 20, 180, 500, 'mg', 'Tablet', false, true, false],
            ['Tempra Forte 250 mg/5 mL Suspension', 'Paracetamol', 'Analgesic/Antipyretic', 145.00, 10, 48, 250, 'mg/5mL', 'Suspension', false, true, true],
            ['Alaxan FR Capsule', 'Ibuprofen + Paracetamol', 'Analgesic', 12.75, 25, 140, 525, 'mg', 'Capsule', false, false, false],
            ['Advil 200 mg Softgel', 'Ibuprofen', 'NSAID', 22.00, 15, 86, 200, 'mg', 'Softgel', false, false, false],
            ['Dolfenal 500 mg Tablet', 'Mefenamic Acid', 'NSAID', 29.50, 12, 72, 500, 'mg', 'Tablet', false, false, false],
            ['Aspirin Protect 100 mg Tablet', 'Aspirin', 'Antiplatelet', 9.25, 20, 150, 100, 'mg', 'Tablet', false, true, false],
            ['Amoxil 500 mg Capsule', 'Amoxicillin', 'Antibiotic', 18.00, 25, 160, 500, 'mg', 'Capsule', false, true, false],
            ['Amoxicillin 250 mg/5 mL Suspension', 'Amoxicillin', 'Antibiotic', 105.00, 8, 42, 250, 'mg/5mL', 'Suspension', false, true, true],
            ['Augmentin 625 mg Tablet', 'Co-amoxiclav', 'Antibiotic', 62.00, 10, 58, 625, 'mg', 'Tablet', false, true, false],
            ['Zithromax 500 mg Tablet', 'Azithromycin', 'Antibiotic', 135.00, 8, 34, 500, 'mg', 'Tablet', false, true, false],
            ['Cefalexin 500 mg Capsule', 'Cefalexin', 'Antibiotic', 22.00, 18, 104, 500, 'mg', 'Capsule', false, true, false],
            ['Ciprobay 500 mg Tablet', 'Ciprofloxacin', 'Antibiotic', 52.00, 10, 54, 500, 'mg', 'Tablet', false, true, false],
            ['Metronidazole 500 mg Tablet', 'Metronidazole', 'Antiprotozoal/Antibiotic', 14.00, 18, 112, 500, 'mg', 'Tablet', false, true, false],
            ['Diflucan 150 mg Capsule', 'Fluconazole', 'Antifungal', 185.00, 5, 22, 150, 'mg', 'Capsule', false, false, false],
            ['Canesten 1% Cream', 'Clotrimazole', 'Antifungal', 176.00, 6, 28, 1, '%', 'Cream', false, false, false],
            ['Tuseran Forte Capsule', 'Dextromethorphan + Phenylpropanolamine + Paracetamol', 'Cough and Cold', 15.50, 20, 120, 650, 'mg', 'Capsule', false, false, false],
            ['Neozep Forte Tablet', 'Phenylephrine + Chlorphenamine + Paracetamol', 'Cough and Cold', 8.75, 30, 260, 500, 'mg', 'Tablet', false, false, false],
            ['Solmux 500 mg Capsule', 'Carbocisteine', 'Mucolytic', 14.50, 20, 130, 500, 'mg', 'Capsule', false, false, false],
            ['Solmux 250 mg/5 mL Syrup', 'Carbocisteine', 'Mucolytic', 138.00, 8, 45, 250, 'mg/5mL', 'Syrup', false, false, true],
            ['Robitussin DM Syrup 60 mL', 'Dextromethorphan + Guaifenesin', 'Cough Suppressant', 155.00, 8, 32, 15, 'mg/5mL', 'Syrup', false, false, true],
            ['Allerta 10 mg Tablet', 'Loratadine', 'Antihistamine', 23.00, 15, 96, 10, 'mg', 'Tablet', false, true, false],
            ['Cetirizine 10 mg Tablet', 'Cetirizine', 'Antihistamine', 9.00, 25, 180, 10, 'mg', 'Tablet', false, true, false],
            ['Otrivin 0.1% Nasal Spray', 'Xylometazoline', 'Nasal Decongestant', 198.00, 5, 24, 1, '%', 'Nasal Spray', false, false, true],
            ['Ventolin 100 mcg Inhaler', 'Salbutamol', 'Bronchodilator', 425.00, 5, 18, 100, 'mcg/dose', 'Inhaler', false, true, true],
            ['Asmalin 2 mg Tablet', 'Salbutamol', 'Bronchodilator', 7.00, 20, 120, 2, 'mg', 'Tablet', false, true, false],
            ['Norvasc 5 mg Tablet', 'Amlodipine', 'Antihypertensive', 24.00, 20, 145, 5, 'mg', 'Tablet', false, true, false],
            ['Amlodipine 10 mg Tablet', 'Amlodipine', 'Antihypertensive', 8.50, 30, 240, 10, 'mg', 'Tablet', false, true, false],
            ['Losartan 50 mg Tablet', 'Losartan Potassium', 'Antihypertensive', 12.00, 30, 220, 50, 'mg', 'Tablet', false, true, false],
            ['Micardis 40 mg Tablet', 'Telmisartan', 'Antihypertensive', 39.00, 12, 68, 40, 'mg', 'Tablet', false, true, false],
            ['Capoten 25 mg Tablet', 'Captopril', 'ACE Inhibitor', 14.00, 18, 92, 25, 'mg', 'Tablet', false, true, false],
            ['Lasix 40 mg Tablet', 'Furosemide', 'Diuretic', 13.50, 18, 88, 40, 'mg', 'Tablet', false, true, false],
            ['Crestor 10 mg Tablet', 'Rosuvastatin', 'Lipid-lowering', 42.00, 12, 62, 10, 'mg', 'Tablet', false, true, false],
            ['Lipitor 20 mg Tablet', 'Atorvastatin', 'Lipid-lowering', 39.00, 12, 76, 20, 'mg', 'Tablet', false, true, false],
            ['Glucophage 500 mg Tablet', 'Metformin', 'Antidiabetic', 11.00, 35, 260, 500, 'mg', 'Tablet', false, true, false],
            ['Diamicron MR 60 mg Tablet', 'Gliclazide', 'Antidiabetic', 28.00, 12, 78, 60, 'mg', 'Modified Release Tablet', false, true, false],
            ['Januvia 100 mg Tablet', 'Sitagliptin', 'Antidiabetic', 88.00, 8, 36, 100, 'mg', 'Tablet', false, true, false],
            ['Lantus SoloStar 100 IU/mL Pen', 'Insulin Glargine', 'Insulin', 820.00, 4, 14, 100, 'IU/mL', 'Pen', false, true, true],
            ['Gaviscon Double Action Tablet', 'Sodium Alginate + Sodium Bicarbonate + Calcium Carbonate', 'Antacid', 18.00, 18, 112, 500, 'mg', 'Chewable Tablet', false, false, false],
            ['Kremil-S Tablet', 'Aluminum Hydroxide + Magnesium Hydroxide + Simethicone', 'Antacid', 8.00, 25, 190, 325, 'mg', 'Chewable Tablet', false, false, false],
            ['Buscopan 10 mg Tablet', 'Hyoscine Butylbromide', 'Antispasmodic', 21.00, 18, 88, 10, 'mg', 'Tablet', false, false, false],
            ['Diatabs 2 mg Capsule', 'Loperamide', 'Antidiarrheal', 14.00, 18, 100, 2, 'mg', 'Capsule', false, false, false],
            ['Losec 20 mg Capsule', 'Omeprazole', 'Proton Pump Inhibitor', 32.00, 12, 84, 20, 'mg', 'Capsule', false, true, false],
            ['Hydrite Sachet', 'Oral Rehydration Salts', 'Rehydration', 18.00, 20, 120, 20, 'g', 'Powder Sachet', false, true, true],
            ['Ceelin 100 mg/5 mL Syrup', 'Ascorbic Acid', 'Vitamin C', 132.00, 10, 50, 100, 'mg/5mL', 'Syrup', false, false, true],
            ['Poten-Cee 500 mg Tablet', 'Ascorbic Acid', 'Vitamin C', 7.50, 30, 240, 500, 'mg', 'Tablet', false, false, false],
            ['Hemarate FA Tablet', 'Iron + Folic Acid', 'Hematinic', 12.00, 18, 110, 1, 'tablet', 'Tablet', false, true, false],
            ['Betadine 10% Solution 60 mL', 'Povidone-Iodine', 'Antiseptic', 128.00, 8, 34, 10, '%', 'Solution', false, false, false],
            ['Alcohol 70% Ethyl 500 mL', 'Ethyl Alcohol', 'Disinfectant', 82.00, 18, 96, 70, '%', 'Solution', false, false, false],
            ['Tramadol 50 mg Capsule', 'Tramadol', 'Controlled Analgesic', 22.00, 5, 24, 50, 'mg', 'Capsule', true, false, false],
            ['Rivotril 2 mg Tablet', 'Clonazepam', 'Controlled Anxiolytic', 24.00, 4, 18, 2, 'mg', 'Tablet', true, false, false],
        ];

        $seededMedicines = [];

        foreach ($medicineSamples as $index => $sample) {
            [$medicineName, $genericName, $category, $price, $reorderLevel, $stocks, $dosage, $unit, $type, $isDangerous, $isYakapEligible, $needsProtection] = $sample;

            $medicineData = [
                'medicine_name' => $medicineName,
                'generic_name' => $genericName,
                'category' => $category,
                'stocks' => $stocks,
                'unit' => $unit,
                'dosage' => $dosage,
                'price' => $price,
                'type' => $type,
                'reorder_level' => $reorderLevel,
                'is_dangerous' => $isDangerous,
                'is_yakap_eligible' => $isYakapEligible,
                'needs_protection' => $needsProtection,
            ];

            $medicine = Medicine::updateOrCreate(
                [
                    'medicine_name' => $medicineName,
                    'generic_name' => $genericName,
                ],
                $medicineData
            );

            $batch = Batch::updateOrCreate(
                ['batch_number' => sprintf('SEED26-%04d', $index + 1)],
                [
                    'expiry_date' => now()->addMonths(18 + ($index % 10))->toDateString(),
                    'received_date' => now()->subDays(20 + ($index % 15))->toDateString(),
                    'mfg_date' => now()->subMonths(4 + ($index % 6))->toDateString(),
                    'location' => $isDangerous
                        ? 'Controlled Cabinet C-' . (($index % 4) + 1)
                        : ($needsProtection ? 'Cold Chain Ref-' . (($index % 3) + 1) : 'Aisle ' . (($index % 8) + 1) . ' Shelf ' . chr(65 + ($index % 6))),
                    'status' => 'active',
                ]
            );

            foreach ([[$branch, $stocks], [$branch2, max(8, (int) floor($stocks * 0.75))]] as [$targetBranch, $branchStocks]) {
                Inventory::updateOrCreate(
                    [
                        'branch_id' => $targetBranch->branch_id,
                        'medicine_id' => $medicine->medicine_id,
                        'batch_id' => $batch->batch_id,
                    ],
                    ['stocks' => $branchStocks]
                );
            }

            $seededMedicines[] = [
                'medicine' => $medicine,
                'batch' => $batch,
                'price' => $price,
            ];
        }

        // -------------------------------------------------
        // 7. Create 50 sample 2025 sales transactions
        // -------------------------------------------------
        foreach ($seededMedicines as $index => $seededMedicine) {
            $targetBranch = $index % 2 === 0 ? $branch : $branch2;
            $branchCode = $targetBranch->branch_id === $branch->branch_id ? 'BR1' : 'BR2';
            $quantity = ($index % 5) + 1;
            $price = (float) $seededMedicine['price'];
            $subTotal = round($quantity * $price, 2);
            $discount = $index % 10 === 0 ? round($subTotal * 0.05, 2) : 0.00;
            $totalAmount = round($subTotal - $discount, 2);
            $usedAmount = ceil($totalAmount / 50) * 50;
            $createdAt = sprintf(
                '2025-%02d-%02d %02d:%02d:00',
                ($index % 12) + 1,
                ($index % 27) + 1,
                9 + ($index % 8),
                ($index * 7) % 60
            );

            $transaction = Transaction::updateOrCreate(
                ['reference_number' => sprintf('%s-2025-SEED-%04d', $branchCode, $index + 1)],
                [
                    'user_id' => $owner->user_id,
                    'branch_id' => $targetBranch->branch_id,
                    'transaction_type' => 'regular',
                    'total_amount' => $totalAmount,
                    'payment_method' => $index % 3 === 0 ? 'GCash' : 'Cash',
                    'sub_total' => $subTotal,
                    'change' => round($usedAmount - $totalAmount, 2),
                    'used_amount' => $usedAmount,
                    'discount' => $discount,
                    'discount_type' => $discount > 0 ? 'Promo' : null,
                    'customer_country' => 'Philippines',
                ]
            );
            $transaction->timestamps = false;
            $transaction->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();

            $transactionItem = TransactionItem::updateOrCreate(
                [
                    'transaction_id' => $transaction->transaction_id,
                    'medicine_id' => $seededMedicine['medicine']->medicine_id,
                    'batch_id' => $seededMedicine['batch']->batch_id,
                ],
                [
                    'batch_number' => $seededMedicine['batch']->batch_number,
                    'expiry_date' => $seededMedicine['batch']->expiry_date,
                    'mfg_date' => $seededMedicine['batch']->mfg_date,
                    'quantity' => $quantity,
                    'price' => $price,
                ]
            );
            $transactionItem->timestamps = false;
            $transactionItem->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();
        }

        // -------------------------------------------------
        // 8. Create the primary admin user
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
