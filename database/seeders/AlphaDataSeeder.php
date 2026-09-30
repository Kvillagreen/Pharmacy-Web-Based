<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\v1\Medicine;
use App\Models\v1\Transaction;
use App\Models\v1\TransactionItem;
use App\Models\v1\User;
use App\Models\v1\Branch;
use App\Models\v1\Company;
use App\Models\v1\Batch;
use App\Models\v1\Inventory;

class AlphaDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates sample companies, branches, users, medicines (N per category) and transactions (M total)
     */
    public function run()
    {
        DB::transaction(function () {
            // Configuration: adjust counts as needed
            $medsPerCategory = 25; // medicines per category
            $transactionCount = 25; // total transactions to create

            $categories = [
                'Analgesic',
                'Antibiotic',
                'Vitamins',
                'Antipyretic',
                'Digestive'
            ];

            // Create a company and branches
            $company = Company::factory()->create();
            $branches = Branch::factory()->count(3)->create(['company_id' => $company->company_id, 'status' => 'active']);

            // Create sample users (staff/customers)
            $users = collect();
            foreach ($branches as $branch) {
                $users = $users->merge(User::factory()->count(4)->create(['branch_id' => $branch->branch_id]));
            }

            // Create medicines per category and collect their IDs
            $allMedicineIds = [];
            foreach ($categories as $category) {
                $medicines = Medicine::factory()->count($medsPerCategory)->create([
                    'category' => $category,
                    'status' => 'active',
                    'cost_price' => 300,
                    'stocks' => 300,
                    'is_dangerous' => false,
                    'needs_protection' => false,
                ]);
                foreach ($medicines as $medicine) {
                    foreach ($branches as $branch) {
                        $batch = Batch::factory()->create([
                            'batch_number' => 'ALPHA-' . $medicine->medicine_id . '-' . $branch->branch_id,
                            'expiry_date' => now()->addYears(2)->toDateString(),
                            'mfg_date' => now()->subYears(2)->toDateString(),
                            'received_date' => now()->subYears(2)->addMonth()->toDateString(),
                        ]);
                        Inventory::factory()->create([
                            'medicine_id' => $medicine->medicine_id, 'branch_id' => $branch->branch_id,
                            'batch_id' => $batch->batch_id, 'stocks' => 100, 'cost_price' => 300,
                        ]);
                    }
                }
                $allMedicineIds = array_merge($allMedicineIds, $medicines->pluck('medicine_id')->toArray());
            }

            // Create transactions and transaction items
            for ($i = 0; $i < $transactionCount; $i++) {
                $branch = $branches->random();
                $user = $users->where('branch_id', $branch->branch_id)->random();

                $transaction = Transaction::factory()->create([
                    'user_id' => $user->user_id,
                    'branch_id' => $branch->branch_id,
                    'payment_method' => 'Cash',
                    'discount_type' => null,
                    'scpwd_id_number' => null,
                ]);

                $itemsCount = rand(1, 4);
                $subTotal = 0.0;
                for ($j = 0; $j < $itemsCount; $j++) {
                    $medId = $allMedicineIds[array_rand($allMedicineIds)];
                    $med = Medicine::find($medId);
                    if (!$med) continue;
                    $qty = rand(1, 5);
                    $price = (float) $med->price;
                    $lineTotal = $price * $qty;
                    $inventory = Inventory::where('medicine_id', $medId)->where('branch_id', $branch->branch_id)->firstOrFail();
                    $batch = Batch::findOrFail($inventory->batch_id);

                    TransactionItem::factory()->create([
                        'transaction_id' => $transaction->transaction_id,
                        'medicine_id' => $medId,
                        'quantity' => $qty,
                        'price' => $price,
                        'cost_price' => $med->cost_price,
                        'batch_id' => $batch->batch_id,
                        'batch_number' => $batch->batch_number,
                        'expiry_date' => $batch->expiry_date,
                        'mfg_date' => $batch->mfg_date,
                    ]);
                    $inventory->decrement('stocks', $qty);
                    $med->decrement('stocks', $qty);

                    $subTotal += $lineTotal;
                }

                $discount = 0.0;
                $vat = 0.0;
                $usedAmount = $subTotal; // simulate full payment

                $transaction->update([
                    'sub_total' => round($subTotal, 2),
                    'total_amount' => round($subTotal - $discount + $vat, 2),
                    'discount' => $discount,
                    'vat_amount' => $vat,
                    'used_amount' => round($usedAmount, 2),
                    'change' => round(max(0, $usedAmount - ($subTotal - $discount + $vat)), 2),
                    'status' => 'completed',
                ]);
            }
        });
    }
}
