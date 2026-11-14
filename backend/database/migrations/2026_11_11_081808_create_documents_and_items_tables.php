<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'products', 'document_series', 'users'] as $name) {
            Schema::table($name, function (Blueprint $t) use ($name): void {
                $t->unique(['company_id', 'id'], $name.'_tenant_id_unique');
            });
        }
        Schema::create('documents', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $t->uuid('customer_id')->nullable();
            $t->uuid('series_id');
            $t->uuid('created_by');
            $t->string('doc_type', 2);
            $t->string('currency', 3);
            $t->string('status', 20)->default('draft');
            $t->date('issue_date');
            $t->bigInteger('number')->nullable();
            $t->timestampTz('issued_at')->nullable();
            foreach (['taxable_cents', 'exempt_cents', 'unaffected_cents', 'igv_cents', 'total_cents'] as $name) {
                $t->bigInteger($name);
            }
            $t->integer('version')->default(1);
            $t->timestampsTz();
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->restrictOnDelete();
            $t->foreign(['company_id', 'series_id'])->references(['company_id', 'id'])->on('document_series')->restrictOnDelete();
            $t->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $t->index(['company_id', 'issue_date', 'id']);
            $t->index(['company_id', 'doc_type', 'status', 'customer_id']);
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_values_check CHECK (doc_type IN ('01','03') AND currency IN ('PEN','USD') AND status IN ('draft','issued') AND version > 0 AND (status <> 'draft' OR (number IS NULL AND issued_at IS NULL)))");
        foreach (['taxable_cents', 'exempt_cents', 'unaffected_cents', 'igv_cents', 'total_cents'] as $name) {
            DB::statement("ALTER TABLE documents ADD CHECK ($name BETWEEN 0 AND 9007199254740991)");
        }
        DB::statement('ALTER TABLE documents ADD CHECK (total_cents = taxable_cents + exempt_cents + unaffected_cents + igv_cents)');
        Schema::create('document_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('document_id');
            $t->uuid('product_id');
            $t->integer('line_number');
            $t->string('description', 250);
            $t->string('unit_code', 3);
            $t->string('tax_type', 2);
            $t->bigInteger('unit_price_cents');
            $t->bigInteger('quantity_millis');
            $t->bigInteger('base_cents');
            $t->bigInteger('igv_cents');
            $t->bigInteger('total_cents');
            $t->unique(['document_id', 'line_number']);
            $t->unique(['document_id', 'product_id']);
            $t->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->cascadeOnDelete();
            $t->foreign(['company_id', 'product_id'])->references(['company_id', 'id'])->on('products')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE document_items ADD CHECK (line_number BETWEEN 1 AND 50 AND quantity_millis BETWEEN 1 AND 1000000000 AND unit_code IN ('NIU','ZZ') AND tax_type IN ('10','20','30'))");
        foreach (['unit_price_cents', 'base_cents', 'igv_cents', 'total_cents'] as $name) {
            DB::statement("ALTER TABLE document_items ADD CHECK ($name BETWEEN 0 AND 9007199254740991)");
        }
        DB::statement('ALTER TABLE document_items ADD CHECK (total_cents = base_cents + igv_cents)');
        // Deferred uniqueness permits rearrangement/replacement without deleting stable item IDs.
        foreach (['document_items_document_id_line_number_unique', 'document_items_document_id_product_id_unique'] as $name) {
            $columns = str_contains($name, 'line_number') ? 'document_id,line_number' : 'document_id,product_id';
            DB::statement("ALTER TABLE document_items DROP CONSTRAINT $name, ADD CONSTRAINT $name UNIQUE ($columns) DEFERRABLE INITIALLY DEFERRED");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_items');
        Schema::dropIfExists('documents');
        foreach (['customers', 'products', 'document_series', 'users'] as $name) {
            Schema::table($name, function (Blueprint $t) use ($name): void {
                $t->dropUnique($name.'_tenant_id_unique');
            });
        }
    }
};
