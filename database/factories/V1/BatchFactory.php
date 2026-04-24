<?php

namespace Database\Factories\v1;

use App\Models\Model;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\v1\Batch;
/**
 * @extends Factory<Batch>
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
            'expiry_date' => $this->faker->date(),
            'received_date' => $this->faker->date(),
            'mfg_date' => $this->faker->date(),
            'location' => $this->faker->address(),
            'status'=> 'active'
        ];
    }
}
