<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emission_outbox', function (Blueprint $t): void {
            $t->string('kind', 12)->default('emission');
            $t->uuid('target_id')->nullable();
            $t->dropUnique(['document_id', 'generation']);
            $t->unique(['document_id', 'kind', 'generation']);
        });
        Schema::create('document_pdfs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('document_id')->unique();
            $t->string('status', 16)->default('pending');
            $t->integer('version')->default(1);
            $t->integer('attempts')->default(0);
            $t->uuid('active_attempt_id')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->timestampTz('next_attempt_at')->nullable();
            $t->uuid('current_file_id')->nullable();
            $t->uuid('requested_by')->nullable();
            $t->string('error_code')->nullable();
            $t->timestampsTz();
            $t->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
        });
        Schema::create('document_pdf_files', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('document_id');
            $t->uuid('attempt_id')->unique();
            $t->string('disk', 32);
            $t->string('path');
            $t->bigInteger('size');
            $t->string('sha256', 64);
            $t->string('template_version', 32);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'document_id', 'id'], 'pdf_file_tenant_unique');
            $t->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
        });
        Schema::table('document_pdfs', function (Blueprint $t): void {
            $t->foreign(['company_id', 'document_id', 'current_file_id'], 'pdf_current_file_fk')->references(['company_id', 'document_id', 'id'])->on('document_pdf_files')->restrictOnDelete();
        });
        Schema::create('document_email_deliveries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('document_id');
            $t->uuid('file_id');
            $t->uuid('requested_by');
            $t->string('recipient', 254);
            $t->string('status', 16)->default('pending');
            $t->integer('sequence');
            $t->uuid('active_attempt_id')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->string('message_id')->unique();
            $t->string('error_code')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->timestampsTz();
            $t->unique(['document_id', 'sequence']);
            $t->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
            $t->foreign(['company_id', 'document_id', 'file_id'], 'email_file_tenant_fk')->references(['company_id', 'document_id', 'id'])->on('document_pdf_files')->restrictOnDelete();
        });
        Schema::create('document_delivery_requests', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('document_id');
            $t->string('operation', 16);
            $t->string('key', 100);
            $t->string('fingerprint', 64);
            $t->jsonb('response');
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'operation', 'key'], 'delivery_request_key_unique');
            $t->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
        });
        Schema::create('document_delivery_attempts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('document_id');
            $t->string('kind', 12);
            $t->uuid('target_id');
            $t->uuid('actor_id')->nullable();
            $t->string('status', 16);
            $t->string('error_code')->nullable();
            $t->timestampTz('created_at');
            $t->timestampTz('finished_at')->nullable();
            $t->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE document_pdfs ADD CHECK (status IN ('pending','generating','ready','failed') AND attempts BETWEEN 0 AND 5 AND version > 0)");
        DB::statement("ALTER TABLE document_email_deliveries ADD CHECK (status IN ('pending','sending','sent','failed','unknown'))");
    }

    public function down(): void
    {
        if (DB::table('document_pdf_files')->exists() || DB::table('document_email_deliveries')->exists()) {
            throw new RuntimeException('Preserve existing private artifacts and delivery audit.');
        }
        Schema::dropIfExists('document_delivery_attempts');
        Schema::dropIfExists('document_delivery_requests');
        Schema::dropIfExists('document_email_deliveries');
        Schema::dropIfExists('document_pdfs');
        Schema::dropIfExists('document_pdf_files');
        DB::table('emission_outbox')->where('kind', '!=', 'emission')->delete();
        Schema::table('emission_outbox', function (Blueprint $t): void {
            $t->dropUnique(['document_id', 'kind', 'generation']);
            $t->dropColumn(['kind', 'target_id']);
            $t->unique(['document_id', 'generation']);
        });
    }
};
