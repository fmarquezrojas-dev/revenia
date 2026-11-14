<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_series', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_type', 2);
            $table->string('code', 4);
            $table->bigInteger('current_number')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'doc_type', 'code'], 'series_company_type_code_unique');
            $table->index(['company_id', 'is_active', 'doc_type', 'code', 'id'], 'series_company_status_index');
        });
        DB::statement("ALTER TABLE document_series ADD CONSTRAINT series_type_check CHECK (doc_type IN ('01', '03', '07'))");
        DB::statement('ALTER TABLE document_series ADD CONSTRAINT series_number_check CHECK (current_number BETWEEN 0 AND 99999999)');
        DB::statement(<<<'SQL'
            ALTER TABLE document_series ADD CONSTRAINT series_code_check CHECK (
                (doc_type = '01' AND code COLLATE "C" ~ '^F[A-Z0-9]{3}$') OR
                (doc_type = '03' AND code COLLATE "C" ~ '^B[A-Z0-9]{3}$') OR
                (doc_type = '07' AND code COLLATE "C" ~ '^[FB][A-Z0-9]{3}$')
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_series');
    }
};
