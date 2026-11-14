<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestampTz('confirmed_at')->nullable();
        });
        DB::statement('UPDATE payments SET confirmed_at = provider_updated_at WHERE applied_cents > 0');
        DB::statement('CREATE INDEX dashboard_payment_activity ON payments (company_id, currency, confirmed_at) WHERE document_id IS NOT NULL AND applied_cents > 0');
        DB::statement('CREATE INDEX dashboard_result_events ON document_events (company_id, to_status, document_id, created_at)');
        DB::statement('CREATE INDEX dashboard_document_portfolio ON documents (company_id, currency, status)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_payment_confirmation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.confirmed_at IS NOT NULL AND NEW.confirmed_at IS DISTINCT FROM OLD.confirmed_at THEN
  RAISE EXCEPTION 'Payment confirmation date is immutable';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER payment_confirmation_immutable BEFORE UPDATE ON payments FOR EACH ROW EXECUTE FUNCTION protect_payment_confirmation();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER payment_confirmation_immutable ON payments; DROP FUNCTION protect_payment_confirmation(); DROP INDEX dashboard_payment_activity; DROP INDEX dashboard_result_events; DROP INDEX dashboard_document_portfolio;');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('confirmed_at'));
    }
};
