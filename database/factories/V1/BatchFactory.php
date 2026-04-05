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
        'supplier_id'=> Supplier::factory(),
            'expiry_date' => $this->faker->date(),
            'received_date' => $this->faker->date(),
            'mfg_date' => $this->faker->date(),
            'location' => $this->faker->address(),
            'status'=> 'active'
        ];
    }
}
