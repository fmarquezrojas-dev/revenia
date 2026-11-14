<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'customer_id' => null, 'created_by' => fn (array $a) => User::factory()->create(['company_id' => $a['company_id']])->id,
            'series_id' => fn (array $a) => DocumentSeries::factory()->create(['company_id' => $a['company_id'], 'doc_type' => '03', 'code' => 'B'.fake()->unique()->numerify('###')])->id,
            'doc_type' => '03', 'currency' => 'PEN', 'status' => 'draft', 'version' => 1, 'issue_date' => now('America/Lima')->toDateString(),
            'taxable_cents' => 0, 'exempt_cents' => 0, 'unaffected_cents' => 0, 'igv_cents' => 0, 'total_cents' => 0];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Document $document): void {
            $product = Product::factory()->create(['company_id' => $document->company_id, 'currency' => $document->currency, 'unit_price_cents' => 0, 'tax_type' => '10']);
            $document->items()->create(['company_id' => $document->company_id, 'product_id' => $product->id, 'line_number' => 1, 'description' => $product->description, 'unit_code' => $product->unit_code, 'tax_type' => '10', 'unit_price_cents' => 0, 'quantity_millis' => 1000, 'base_cents' => 0, 'igv_cents' => 0, 'total_cents' => 0]);
        });
    }
}
