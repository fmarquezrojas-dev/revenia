<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'doc_type' => 'dni',
            'doc_number' => fake()->unique()->numerify('########'),
            'name' => fake()->name(),
            'address' => null,
            'email' => null,
            'is_active' => true,
        ];
    }
}
