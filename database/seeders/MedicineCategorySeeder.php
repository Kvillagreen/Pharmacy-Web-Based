<?php

namespace Database\Seeders;

use App\Models\v1\Medicine;
use App\Models\v1\Inventory;
use App\Models\v1\Branch;
use App\Models\v1\Batch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MedicineCategorySeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Populating missing medicines per category (minimum 5 each)...');

        $existingCategories = Medicine::select('category')->distinct()->pluck('category')->toArray();
        $defaultCategories = ['Analgesic', 'Biogesic', 'Paracetamol', 'Antibiotic', 'Vitamins', 'Cough Syrup', 'Antihistamine'];
        
        $allCategories = array_unique(array_merge($existingCategories, $defaultCategories));
        
        $branches = Branch::where('status', 'active')->get();
        if ($branches->isEmpty()) {
            $branches = Branch::all();
        }

        $totalAdded = 0;

        foreach ($allCategories as $category) {
            $count = Medicine::where('category', $category)->count();
            if ($count < 5) {
                $needed = 5 - $count;
                $this->command->info("Category '{$category}' has {$count} medicines. Adding {$needed} more...");
                
                $medicines = Medicine::factory()->count($needed)->create([
                    'category' => $category,
                ]);

                $totalAdded += $needed;

                // Ensure they have basic inventory so transactions don't fail later
                foreach ($medicines as $medicine) {
                    foreach ($branches as $branch) {
                        $batch = Batch::where('status', 'active')->inRandomOrder()->first();
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

                        Inventory::create([
                            'branch_id' => $branch->branch_id,
                            'medicine_id' => $medicine->medicine_id,
                            'batch_id' => $batch->batch_id,
                            'stocks' => rand(50, 150),
                        ]);
                    }

                    // Update total stocks on medicine level
                    $totalStocks = Inventory::where('medicine_id', $medicine->medicine_id)->sum('stocks');
                    $medicine->update(['stocks' => $totalStocks]);
                }
            }
        }

        $this->command->info("Finished adding {$totalAdded} medicines to meet the minimum threshold.");
    }
}
