<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_type', 5);
            $table->string('doc_number', 20);
            $table->string('name', 200);
            $table->string('address', 300)->nullable();
            $table->string('email', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'doc_type', 'doc_number'], 'customers_company_document_unique');
            $table->index(['company_id', 'name', 'id'], 'customers_company_listing_index');
            $table->index(['company_id', 'is_active', 'name', 'id'], 'customers_company_status_index');
        });

        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_doc_type_check CHECK (doc_type IN ('ruc', 'dni', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
