<?php

namespace Database\Factories\v1;

use App\Models\v1\Permission;
use App\Models\v1\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [

            'branch_id' => User::inRandomOrder()->first()->branch_id ?? User::factory(),
        ];
    }
}
