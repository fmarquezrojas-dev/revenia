<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('document_id');
            $t->uuid('requested_by');
            $t->string('provider', 30)->default('mercadopago');
            $t->bigInteger('amount_cents');
            $t->string('currency', 3);
            $t->string('status', 20)->default('pending');
            $t->string('preference_id', 150)->nullable()->unique();
            $t->text('checkout_url')->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('last_reconciled_at')->nullable();
            $t->string('error_code', 80)->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'document_id', 'id']);
            $t->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
            $t->foreign(['company_id', 'requested_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $t->index(['company_id', 'document_id', 'created_at']);
        });
        DB::statement("ALTER TABLE payment_orders ADD CHECK (amount_cents > 0 AND amount_cents <= 9007199254740991 AND currency='PEN' AND status IN ('pending','creating','open','unknown','closed','expired','stale','failed'))");
        DB::statement("CREATE UNIQUE INDEX payment_orders_single_active ON payment_orders(document_id) WHERE status IN ('pending','creating','open','unknown')");
        Schema::create('payments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('document_id');
            $t->uuid('order_id');
            $t->string('provider', 30)->default('mercadopago');
            $t->string('provider_id', 25);
            $t->bigInteger('amount_cents');
            $t->bigInteger('applied_cents')->default(0);
            $t->bigInteger('refunded_cents')->default(0);
            $t->string('currency', 3);
            $t->string('status', 40);
            $t->boolean('needs_review')->default(false);
            $t->string('review_code', 80)->nullable();
            $t->timestampTz('provider_updated_at');
            $t->timestampsTz();
            $t->unique(['provider', 'provider_id']);
            $t->foreign(['company_id', 'document_id', 'order_id'])->references(['company_id', 'document_id', 'id'])->on('payment_orders')->restrictOnDelete();
            $t->index(['company_id', 'document_id']);
        });
        DB::statement('ALTER TABLE payments ADD CHECK (amount_cents > 0 AND amount_cents <= 9007199254740991 AND applied_cents BETWEEN 0 AND amount_cents AND refunded_cents BETWEEN 0 AND amount_cents)');
        Schema::create('payment_requests', function (Blueprint $t): void {
            $t->uuid('company_id');
            $t->string('key', 100);
            $t->string('operation', 20);
            $t->uuid('document_id');
            $t->char('fingerprint', 64);
            $t->jsonb('response');
            $t->timestampTz('created_at');
            $t->primary(['company_id', 'operation', 'key']);
            $t->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
        });
        // Durable inbox/outbox: webhook company is unknown until provider lookup verifies the local reference.
        Schema::create('payment_work', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('kind', 20);
            $t->uuid('company_id')->nullable();
            $t->uuid('order_id')->nullable();
            $t->string('payment_id', 25)->nullable();
            $t->string('event_id', 100)->nullable();
            $t->char('dedup_key', 64)->unique();
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->uuid('token')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->timestampTz('available_at');
            $t->timestampTz('published_at')->nullable();
            $t->string('error_code', 80)->nullable();
            $t->timestampsTz();
            $t->index(['status', 'available_at']);
            $t->index(['company_id', 'order_id']);
        });
        Schema::create('payment_audit', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->nullable();
            $t->uuid('document_id')->nullable();
            $t->uuid('order_id')->nullable();
            $t->uuid('work_id')->nullable();
            $t->string('payment_id', 25)->nullable();
            $t->string('event', 60);
            $t->string('code', 80)->nullable();
            $t->timestampTz('created_at');
            $t->index(['company_id', 'document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('payment_orders')->exists() || DB::table('payment_work')->exists()) {
            throw new RuntimeException('Conservar ordenes y auditoria financiera.');
        }
        foreach (['payment_audit', 'payment_work', 'payment_requests', 'payments', 'payment_orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
