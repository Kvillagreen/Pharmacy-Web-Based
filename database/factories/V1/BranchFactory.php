<?php

namespace Database\Factories\V1;

use App\Models\v1\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
   public function definition(): array
    {

        return [
            "branch_name"=> $this->faker->company(),
            "branch_address"=> $this->faker->address(),
            'branch_contact' =>$this->faker->phoneNumber(),
            "status"=> "active",
        ];
    }
}
