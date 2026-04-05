<?php

namespace Database\Factories\v1;

use App\Models\Model;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\v1\Batch;
use App\Models\v1\Medicine;
use App\Models\v1\Supplier;
use App\Models\v1\Branch;
/**
 * @extends Factory<Batch>
 * @extends Factory<Medicine>
 * @extends Factory<Supplier>
 * @extends Factory<Branch>
 */
class InventoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
         return [

            'medicine_id' =>Medicine::factory(),
            'branch_id' => Branch::factory(),
            'batch_id' => Batch::factory(),
        ];
    }
}
