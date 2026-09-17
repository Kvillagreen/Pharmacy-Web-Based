<?php

namespace Database\Seeders;

use App\Models\v1\Batch;
use App\Models\v1\Branch;
use App\Models\v1\Inventory;
use App\Models\v1\Medicine;
use App\Models\v1\Transaction;
use App\Models\v1\TransactionItem;
use App\Models\v1\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StockAndTransactionSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('1. Populating and updating stocks for all branches...');

        // Fetch active branches
        $branches = Branch::where('status', 'active')->get();
        if ($branches->isEmpty()) {
            $branches = Branch::all();
        }

        $medicines = Medicine::all();

        if ($branches->isEmpty() || $medicines->isEmpty()) {
            $this->command->error('Branches or Medicines table is empty. Please ensure basic data exists first.');
            return;
        }

        // For each medicine and each branch, ensure active batch and inventory stock exists
        foreach ($medicines as $medicine) {
            foreach ($branches as $branch) {
                // Ensure a valid batch exists
                $batch = Batch::where('status', 'active')
                    ->where('expiry_date', '>', now()->addMonths(6))
                    ->inRandomOrder()
                    ->first();

                if (!$batch) {
                    $batch = Batch::create([
                        'batch_number' => 'BCH-' . strtoupper(Str::random(6)),
                        'expiry_date' => now()->addMonths(rand(8, 24))->toDateString(),
                        'mfg_date' => now()->subMonths(rand(1, 6))->toDateString(),
                        'received_date' => now()->subDays(rand(5, 60))->toDateString(),
                        'location' => 'Shelf-' . rand(1, 10),
                        'status' => 'active',
                    ]);
                }

                $stockAmount = rand(75, 260);

                $inventory = Inventory::where('branch_id', $branch->branch_id)
                    ->where('medicine_id', $medicine->medicine_id)
                    ->first();

                if ($inventory) {
                    $inventory->update([
                        'stocks' => $stockAmount,
                        'batch_id' => $inventory->batch_id ?: $batch->batch_id,
                    ]);
                } else {
                    Inventory::create([
                        'branch_id' => $branch->branch_id,
                        'medicine_id' => $medicine->medicine_id,
                        'batch_id' => $batch->batch_id,
                        'stocks' => $stockAmount,
                    ]);
                }
            }
        }

        // Aggregate total stocks in medicine table
        foreach ($medicines as $med) {
            $totalStocks = Inventory::where('medicine_id', $med->medicine_id)->sum('stocks');
            $med->update(['stocks' => $totalStocks]);
        }

        $this->command->info('Stocks updated for ' . $medicines->count() . ' medicines across ' . $branches->count() . ' branches.');

        $this->command->info('2. Generating transactions for each branch...');

        $paymentMethods = ['cash', 'gcash', 'maya', 'credit_card'];
        $discountTypes = ['none', 'none', 'none', 'senior', 'pwd'];
        $samplePatients = [
            'Juan Dela Cruz', 'Maria Santos', 'Pedro Penduko', 'Ana Reyes',
            'Jose Rizal', 'Elena Gomez', 'Roberto Garcia', 'Carmela Diaz',
            'Antonio Luna', 'Teresa Magbanua', 'Gabriel Silang', 'Corazon Ramos',
            'Emilio Aguinaldo', 'Liza Soberano', 'Enrique Gil', 'Kathryn Bernardo',
            'Daniel Padilla', 'Alden Richards', 'Maine Mendoza', 'Marian Rivera'
        ];
        $sampleDoctors = [
            'Dr. Ramon Reyes, MD - Lic. #0128453',
            'Dr. Sofia Mendoza, MD - Lic. #0093821',
            'Dr. Carlos Aquino, MD - Lic. #0047261',
            'Dr. Patricia Lim, MD - Lic. #0119284',
            'Dr. Ferdinand Cruz, MD - Lic. #0081726'
        ];

        $users = User::all();
        $totalCreated = 0;

        foreach ($branches as $branch) {
            $branchUsers = $users->where('branch_id', $branch->branch_id);
            if ($branchUsers->isEmpty()) {
                $branchUsers = $users;
            }

            // Create 50 transactions per branch spread over the past 45 days
            for ($i = 0; $i < 50; $i++) {
                $cashier = $branchUsers->random();
                $daysAgo = rand(0, 45);
                $transactionDate = Carbon::now()->subDays($daysAgo)->subHours(rand(1, 12))->subMinutes(rand(0, 59));

                $branchInventories = Inventory::with(['medicine', 'batch'])
                    ->where('branch_id', $branch->branch_id)
                    ->inRandomOrder()
                    ->take(rand(1, 4))
                    ->get();

                if ($branchInventories->isEmpty()) {
                    continue;
                }

                $discountType = $discountTypes[array_rand($discountTypes)];
                $isDiscounted = in_array($discountType, ['senior', 'pwd']);
                $patientName = $samplePatients[array_rand($samplePatients)];
                $paymentMethod = $paymentMethods[array_rand($paymentMethods)];

                $hasRegulated = false;
                $itemsData = [];
                $subTotal = 0;

                foreach ($branchInventories as $inv) {
                    if (!$inv->medicine) continue;
                    $qty = rand(1, 5);
                    $unitPrice = (float) $inv->medicine->price;
                    $itemTotal = round($unitPrice * $qty, 2);
                    $subTotal += $itemTotal;

                    if ($inv->medicine->needs_protection || $inv->medicine->is_dangerous) {
                        $hasRegulated = true;
                    }

                    $itemsData[] = [
                        'medicine_id' => $inv->medicine->medicine_id,
                        'batch_id' => $inv->batch_id,
                        'batch_number' => $inv->batch?->batch_number ?? ('BCH-' . strtoupper(Str::random(6))),
                        'expiry_date' => $inv->batch?->expiry_date ?? now()->addYear()->toDateString(),
                        'mfg_date' => $inv->batch?->mfg_date ?? now()->subMonths(3)->toDateString(),
                        'quantity' => $qty,
                        'price' => $unitPrice,
                        'created_at' => $transactionDate,
                        'updated_at' => $transactionDate,
                    ];
                }

                if (empty($itemsData) || $subTotal <= 0) {
                    continue;
                }

                $discountAmount = 0.00;
                if ($isDiscounted) {
                    $discountAmount = round($subTotal * 0.20, 2);
                }

                $totalAmount = round(max($subTotal - $discountAmount, 0), 2);
                $usedAmount = $paymentMethod === 'cash' ? ceil($totalAmount / 50) * 50 : $totalAmount;
                if ($usedAmount < $totalAmount) {
                    $usedAmount = $totalAmount + rand(10, 100);
                }
                $change = round($usedAmount - $totalAmount, 2);

                $regulatedClassification = null;
                $regulatedDetails = null;

                if ($hasRegulated) {
                    $regulatedClassification = rand(0, 1) ? 'Prescription Medicine' : 'Dangerous Drug';
                    $regulatedDetails = [
                        'doctor_name' => $sampleDoctors[array_rand($sampleDoctors)],
                        'prescription_number' => 'RX-' . rand(100000, 999999),
                        'valid_until' => $transactionDate->copy()->addDays(30)->toDateString(),
                        'identification_type' => 'Driver License',
                        'identification_number' => 'N' . rand(10, 99) . '-' . rand(10, 99) . '-' . rand(100000, 999999),
                    ];
                }

                $isVoided = ($i % 25 === 0);
                $status = $isVoided ? 'voided' : 'completed';

                $transaction = Transaction::create([
                    'user_id' => $cashier->user_id,
                    'branch_id' => $branch->branch_id,
                    'transaction_type' => $hasRegulated ? 'regulated_sale' : 'regular_sale',
                    'total_amount' => $totalAmount,
                    'payment_method' => $paymentMethod,
                    'reference_number' => $paymentMethod !== 'cash' ? 'REF-' . strtoupper(Str::random(10)) : null,
                    'sub_total' => $subTotal,
                    'change' => $change,
                    'used_amount' => $usedAmount,
                    'discount' => $discountAmount,
                    'discount_type' => $discountType,
                    'scpwd_id_number' => $isDiscounted ? 'ID-' . rand(10000, 99999) : null,
                    'patient_name' => $patientName,
                    'regulated_classification' => $regulatedClassification,
                    'regulated_details' => $regulatedDetails,
                    'status' => $status,
                    'created_at' => $transactionDate,
                    'updated_at' => $transactionDate,
                ]);

                foreach ($itemsData as $item) {
                    $item['transaction_id'] = $transaction->transaction_id;
                    TransactionItem::create($item);
                }

                $totalCreated++;
            }
        }

        $this->command->info("Finished populating {$totalCreated} transactions across all branches!");
    }
}
