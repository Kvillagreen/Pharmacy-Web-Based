<?php

namespace Database\Factories\V1;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\v1\Company;

class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            "company_name" => $this->faker->company(),
            "tin_number" => $this->faker->numerify('##########'),
            "company_email" => $this->faker->unique()->companyEmail(),
        ];
    }
}
