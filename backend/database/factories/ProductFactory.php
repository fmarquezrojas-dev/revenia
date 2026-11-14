<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'code' => fake()->unique()->numerify('P-########'),
            'description' => fake()->sentence(), 'unit_code' => 'NIU', 'currency' => 'PEN',
            'unit_price_cents' => 1050, 'tax_type' => '10', 'is_active' => true];
    }
}
