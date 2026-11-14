<?php

namespace Database\Factories;

use App\Actions\Subscriptions\Subscriptions;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'ruc' => $this->makeRuc(),
            'legal_name' => fake()->company(),
            'trade_name' => fake()->company(),
            'address' => fake()->address(),
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Company $company): void {
            if (app()->environment('testing') && config('subscriptions.test_fixture_grants', true)) {
                app(Subscriptions::class)->grantDemo($company->id);
            }
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    private function makeRuc(): string
    {
        $base = '20'.fake()->unique()->numerify('########');
        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += ((int) $base[$index]) * $weight;
        }

        $digit = 11 - ($sum % 11);

        $digit = match ($digit) {
            10 => 0,
            11 => 1,
            default => $digit,
        };

        return $base.$digit;
    }
}
