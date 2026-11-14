<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('description', 250);
            $table->string('unit_code', 3);
            $table->string('currency', 3);
            $table->bigInteger('unit_price_cents');
            $table->string('tax_type', 2);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'code'], 'products_company_code_unique');
            $table->index(['company_id', 'description', 'id'], 'products_company_listing_index');
            $table->index(['company_id', 'is_active', 'currency', 'tax_type'], 'products_company_filters_index');
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_currency_check CHECK (currency IN ('PEN', 'USD'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_unit_check CHECK (unit_code IN ('NIU', 'ZZ'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_tax_check CHECK (tax_type IN ('10', '20', '30'))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_check CHECK (unit_price_cents BETWEEN 0 AND 9007199254740991)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
