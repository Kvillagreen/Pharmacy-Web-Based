<?php

namespace Database\Factories\v1;

use App\Models\Model;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\v1\Batch;
use App\Models\v1\Medicine;
use App\Models\v1\Supplier;
/**
 * @extends Factory<Batch>
 * @extends Factory<Medicine>
 * @extends Factory<Supplier>
 */
class BatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
       return [
            'medicine_id' => Medicine::inRandomOrder()->first()->medicine_id ?? Medicine::factory(),
            'supplier_id' => Supplier::inRandomOrder()->first()->supplier_id ?? Supplier::factory(),
            'expiry_date' => $this->faker->date(),
            'received_date' => $this->faker->date(),
            'status'=> 'active'
        ];
    }
}
