<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_demo_records', function (Blueprint $table): void {
            $table->uuid('company_id');
            $table->string('dataset', 60);
            $table->string('kind', 30);
            $table->string('scenario', 80);
            $table->uuid('record_id');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at');
            $table->primary(['company_id', 'dataset', 'kind', 'scenario']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('local_demo_records')->exists()) {
            throw new RuntimeException('Conservar el registro del dataset evita duplicar emisiones.');
        } Schema::dropIfExists('local_demo_records');
    }
};
