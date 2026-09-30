<?php

namespace Database\Factories\v1;

use App\Models\v1\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Permission> */
class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    public function definition(): array
    {
        return [
            'permission_name' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
        ];
    }
}
