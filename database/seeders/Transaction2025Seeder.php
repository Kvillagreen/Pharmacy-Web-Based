<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\v1\User;
use App\Models\v1\Branch;
use App\Models\v1\Medicine;
use App\Models\v1\Inventory;
use App\Models\v1\Batch;
use App\Models\v1\Transaction;
use App\Models\v1\TransactionItem;
use Illuminate\Support\Facades\Schema;

class Transaction2025Seeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();
        $users = User::all();
        $inventories = Inventory::with(['medicine', 'batch'])->get();

        if ($branches->isEmpty() || $users->isEmpty() || $inventories->isEmpty()) {
            $this->command->warn('Ensure users, branches, and inventory are seeded before running this.');
            return;
        }

        $this->command->info('Seeding 500 transactions for 2025...');

        for ($i = 0; $i < 500; $i++) {
            $branch = $branches->random();
            $user = $users->where('branch_id', $branch->branch_id)->first() ?? $users->first();
            
            // Randomly select 1 to 5 distinct items from inventory for this branch
            $branchInventories = $inventories->where('branch_id', $branch->branch_id);
            if ($branchInventories->isEmpty()) {
                continue;
            }
            $sampleCount = min(5, $branchInventories->count());
            $branchInventories = $branchInventories->random(rand(1, $sampleCount));

            $subTotal = 0;
            $items = [];
            foreach ($branchInventories as $inventory) {
                $quantity = rand(1, 5);
                $price = $inventory->medicine->price;
                $costPrice = round($price * 0.7, 2); // 70% of price
                $lineTotal = $price * $quantity;
                $subTotal += $lineTotal;

                $items[] = [
                    'medicine_id' => $inventory->medicine_id,
                    'batch_id' => $inventory->batch_id,
                    'batch_number' => $inventory->batch->batch_number ?? null,
                    'expiry_date' => $inventory->batch->expiry_date,
                    'mfg_date' => $inventory->batch->mfg_date,
                    'quantity' => $quantity,
                    'price' => $price,
                    'cost_price' => $costPrice,
                ];
            }

            $hasDiscount = rand(1, 100) <= 20; // 20% chance of discount
            $discountType = null;
            $discount = 0;

            if ($hasDiscount) {
                $discountType = rand(1, 100) <= 50 ? 'Senior Citizen' : 'Promo';
                $discount = round($subTotal * ($discountType === 'Senior Citizen' ? 0.20 : 0.05), 2);
            }

            $vatableSales = $discountType === 'Senior Citizen' ? 0 : round($subTotal / 1.12, 2);
            $vatAmount = $discountType === 'Senior Citizen' ? 0 : round($subTotal - $vatableSales, 2);
            $totalAmount = round($subTotal - $discount, 2);

            // simulate payment
            $usedAmount = ceil($totalAmount / 100) * 100;
            if ($usedAmount < $totalAmount) {
                $usedAmount = $totalAmount;
            }
            $change = round($usedAmount - $totalAmount, 2);
            
            $paymentMethod = rand(1, 100) <= 30 ? 'GCash' : 'Cash';
            $transactionType = $discountType ? 'discounted' : 'regular';
            $status = rand(1, 100) <= 5 ? 'voided' : 'completed';

            // Random date in 2025
            $createdAt = sprintf(
                '2025-%02d-%02d %02d:%02d:%02d',
                rand(1, 12),
                rand(1, 28),
                rand(8, 20),
                rand(0, 59),
                rand(0, 59)
            );

            $branchCode = 'BR' . $branch->branch_id;
            $referenceNumber = sprintf('%s-2025-SEED-B-%04d', $branchCode, $i + 1);

            $transactionData = [
                'user_id' => $user->user_id,
                'branch_id' => $branch->branch_id,
                'transaction_type' => $transactionType,
                'total_amount' => $totalAmount,
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,
                'sub_total' => $subTotal,
                'change' => $change,
                'discount' => $discount,
                'vat_amount' => $vatAmount,
                'discount_type' => $discountType,
                'scpwd_id_number' => $discountType === 'Senior Citizen' ? 'SC-' . rand(10000, 99999) : null,
                'used_amount' => $usedAmount,
                'status' => $status,
                'customer_country' => 'Philippines',
                'voided_at' => $status === 'voided' ? $createdAt : null,
                'void_reason' => $status === 'voided' ? 'Customer changed mind' : null,
                'voided_by_user_id' => $status === 'voided' ? $user->user_id : null,
            ];

            $transaction = Transaction::updateOrCreate(
                ['reference_number' => $referenceNumber],
                $transactionData
            );

            $transaction->timestamps = false;
            $transaction->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();

            foreach ($items as $itemData) {
                $itemData['transaction_id'] = $transaction->transaction_id;
                
                $transactionItem = TransactionItem::updateOrCreate(
                    [
                        'transaction_id' => $transaction->transaction_id,
                        'medicine_id' => $itemData['medicine_id'],
                        'batch_id' => $itemData['batch_id'],
                    ],
                    $itemData
                );

                $transactionItem->timestamps = false;
                $transactionItem->forceFill([
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ])->save();
            }
        }
    }
}
