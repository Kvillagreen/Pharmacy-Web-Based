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

class Year2025TransactionSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Populating deterministic report transactions for 2025-2026...');
        mt_srand(20250830);

        $branches = Branch::where('status', 'active')->get();
        if ($branches->isEmpty()) {
            $branches = Branch::all();
        }

        $medicines = Medicine::all();
        $users = User::all();

        if ($branches->isEmpty() || $medicines->isEmpty()) {
            $this->command->error('Branches or Medicines table is empty.');
            return;
        }

        $paymentMethods = ['Cash', 'Cash', 'Gcash', 'Card'];
        $discountTypes = ['none', 'none', 'none', 'senior', 'pwd'];
        $samplePatients = [
            'Juan Dela Cruz', 'Maria Santos', 'Pedro Penduko', 'Ana Reyes',
            'Jose Rizal', 'Elena Gomez', 'Roberto Garcia', 'Carmela Diaz',
            'Antonio Luna', 'Teresa Magbanua', 'Gabriel Silang', 'Corazon Ramos',
            'Emilio Aguinaldo', 'Liza Soberano', 'Enrique Gil', 'Kathryn Bernardo',
            'Daniel Padilla', 'Alden Richards', 'Maine Mendoza', 'Marian Rivera',
            'Dingdong Dantes', 'Coco Martin', 'Vic Sotto', 'Joey de Leon'
        ];
        $sampleDoctors = [
            'Dr. Ramon Reyes, MD - Lic. #0128453',
            'Dr. Sofia Mendoza, MD - Lic. #0093821',
            'Dr. Carlos Aquino, MD - Lic. #0047261',
            'Dr. Patricia Lim, MD - Lic. #0119284',
            'Dr. Ferdinand Cruz, MD - Lic. #0081726'
        ];

        $totalTransactions = 0;

        foreach ([2025, 2026] as $year) {
        $lastMonth = $year === 2025 ? 12 : (int) now()->format('n');
        for ($month = 1; $month <= $lastMonth; $month++) {
            // Generate 12-18 transactions per month per branch
            foreach ($branches as $branch) {
                $branchUsers = $users->where('branch_id', $branch->branch_id);
                if ($branchUsers->isEmpty()) {
                    $branchUsers = $users;
                }

                $txCount = rand(12, 18);

                for ($i = 0; $i < $txCount; $i++) {
                    $day = rand(1, 28);
                    $hour = rand(8, 20);
                    $minute = rand(0, 59);
                    $second = rand(0, 59);
                    $transactionDate = Carbon::create($year, $month, $day, $hour, $minute, $second);
                    if ($transactionDate->isFuture()) {
                        continue;
                    }

                    $cashier = $branchUsers->random();

                    // Select 1 to 4 random medicines available in this branch
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
                    $hasDangerous = false;
                    $hasControlled = false;
                    $itemsData = [];
                    $subTotal = 0;

                    foreach ($branchInventories as $inv) {
                        if (!$inv->medicine) continue;
                        $qty = rand(1, 6);
                        $unitPrice = (float) $inv->medicine->price;
                        $itemTotal = round($unitPrice * $qty, 2);
                        $subTotal += $itemTotal;

                        if ($inv->medicine->needs_protection || $inv->medicine->is_dangerous) {
                            $hasRegulated = true;
                        }
                        $hasDangerous = $hasDangerous || (bool) $inv->medicine->is_dangerous;
                        $hasControlled = $hasControlled || (bool) $inv->medicine->needs_protection;

                        $itemsData[] = [
                            'medicine_id' => $inv->medicine->medicine_id,
                            'batch_id' => $inv->batch_id,
                            'batch_number' => $inv->batch?->batch_number ?? ('BCH-2025-' . strtoupper(Str::random(5))),
                            'expiry_date' => $inv->batch?->expiry_date ?? Carbon::create(2026, rand(1, 12), rand(1, 28))->toDateString(),
                            'mfg_date' => $inv->batch?->mfg_date ?? Carbon::create(2024, rand(1, 12), rand(1, 28))->toDateString(),
                            'quantity' => $qty,
                            'price' => $unitPrice,
                            'cost_price' => (float) ($inv->cost_price ?? $inv->medicine->cost_price ?? 0),
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
                            'prescription_number' => 'RX-' . $year . '-' . rand(100000, 999999),
                            'valid_until' => $transactionDate->copy()->addDays(30)->toDateString(),
                            'identification_type' => 'Driver License',
                            'identification_number' => 'N' . rand(10, 99) . '-' . rand(10, 99) . '-' . rand(100000, 999999),
                        ];
                    }

                    $isVoided = ($i % 30 === 0);
                    $status = $isVoided ? 'voided' : 'completed';

                    $testReference = 'TST' . $year . str_pad((string) $month, 2, '0', STR_PAD_LEFT)
                        . str_pad((string) $branch->branch_id, 2, '0', STR_PAD_LEFT)
                        . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
                    if (Transaction::where('reference_number', $testReference)->exists()) {
                        continue;
                    }

                    $transactionType = $hasDangerous && $hasControlled
                        ? 'mixed'
                        : ($hasDangerous ? 'dangerous' : ($hasControlled ? 'controlled' : 'regular'));

                    $transaction = Transaction::create([
                        'user_id' => $cashier->user_id,
                        'branch_id' => $branch->branch_id,
                        'transaction_type' => $transactionType,
                        'total_amount' => $totalAmount,
                        'payment_method' => $paymentMethod,
                        'reference_number' => $testReference,
                        'sub_total' => $subTotal,
                        'change' => $change,
                        'used_amount' => $usedAmount,
                        'discount' => $discountAmount,
                        'discount_type' => $discountType,
                        'scpwd_id_number' => $isDiscounted ? 'OSCA-' . rand(10000000, 99999999) : null,
                        'patient_name' => $patientName,
                        'regulated_classification' => $hasRegulated ? $transactionType : null,
                        'regulated_details' => $regulatedDetails,
                        'status' => $status,
                        'created_at' => $transactionDate,
                        'updated_at' => $transactionDate,
                    ]);

                    foreach ($itemsData as $item) {
                        $item['transaction_id'] = $transaction->transaction_id;
                        TransactionItem::create($item);
                    }

                    $totalTransactions++;
                }
            }
        }
        }

        $this->command->info("Successfully generated {$totalTransactions} test transactions for 2025-2026.");
    }
}
