<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\DocumentSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentSeries> */
class DocumentSeriesFactory extends Factory
{
    protected $model = DocumentSeries::class;

    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'doc_type' => '01',
            'code' => 'F'.fake()->unique()->numerify('###'), 'current_number' => 0, 'is_active' => true];
    }
}
