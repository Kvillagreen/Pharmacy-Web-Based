<?php

namespace Database\Factories\V1;

use App\Models\v1\User;
use App\Models\v1\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition()
    {
        return [
            'branch_id' => Branch::inRandomOrder()->first()->branch_id ?? Branch::factory(),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('admin123'),
            'role' => $this->faker->randomElement(['admin', 'pharmacist', 'staff', 'owner', 'branch_manager']),
            'address' => $this->faker->address(),
        ];
    }


}
