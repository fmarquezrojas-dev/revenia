<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('payment_terms', 6)->nullable();
            $table->date('due_date')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE documents ADD CONSTRAINT documents_payment_terms_valid CHECK (
                (payment_terms IS NULL AND due_date IS NULL) OR
                (doc_type IN ('01', '03') AND payment_terms IS NOT NULL AND due_date IS NOT NULL AND
                    ((payment_terms = 'cash' AND due_date = issue_date) OR
                     (payment_terms = 'credit' AND due_date >= issue_date)))
            )
            SQL);
        DB::statement("CREATE INDEX documents_receivables_due_idx ON documents (company_id, currency, due_date, issue_date, id) WHERE status = 'accepted' AND doc_type IN ('01', '03')");
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['payment_terms', 'due_date']);
        });
    }
};
