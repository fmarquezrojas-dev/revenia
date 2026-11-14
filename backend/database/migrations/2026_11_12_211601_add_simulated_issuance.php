<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t): void {
            $t->jsonb('fiscal_snapshot')->nullable();
            $t->uuid('submission_id')->nullable()->unique();
            $t->string('series_code', 4)->nullable();
            $t->integer('attempt_count')->default(0);
            $t->uuid('active_attempt_id')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->timestampTz('next_attempt_at')->nullable();
            $t->string('result_code')->nullable();
            $t->string('result_message')->nullable();
            $t->string('failure_kind')->nullable();
            $t->boolean('can_retry')->default(false);
            $t->unique(['company_id', 'doc_type', 'series_id', 'number'], 'documents_fiscal_number_unique');
        });
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_values_check');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_values_check CHECK (doc_type IN ('01','03') AND currency IN ('PEN','USD') AND version > 0 AND status IN ('draft','issued','queued','sending','accepted','rejected','failed') AND (status <> 'draft' OR (number IS NULL AND issued_at IS NULL)) AND (status IN ('draft','issued') OR (number IS NOT NULL AND issued_at IS NOT NULL AND number BETWEEN 1 AND 99999999 AND submission_id IS NOT NULL AND fiscal_snapshot IS NOT NULL AND series_code IS NOT NULL)))");
        foreach (['emission_requests', 'document_events', 'emission_outbox', 'emission_attempts', 'simulated_submissions'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table): void {
                $t->uuid('id')->primary();
                $t->uuid('company_id');
                $t->uuid('document_id');
                $t->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
                $t->timestampTz('created_at')->useCurrent();
                if ($table === 'emission_requests') {
                    $t->string('operation', 10);
                    $t->string('key', 100);
                    $t->string('fingerprint', 64);
                    $t->jsonb('response');
                    $t->unique(['company_id', 'operation', 'key']);
                } elseif ($table === 'document_events') {
                    $t->uuid('actor_id')->nullable();
                    $t->uuid('attempt_id')->nullable();
                    $t->string('type');
                    $t->string('from_status');
                    $t->string('to_status');
                    $t->string('code')->nullable();
                    $t->string('message')->nullable();
                    $t->index(['company_id', 'document_id', 'created_at']);
                } elseif ($table === 'emission_outbox') {
                    $t->integer('generation');
                    $t->timestampTz('available_at');
                    $t->timestampTz('published_at')->nullable();
                    $t->uuid('claim_token')->nullable();
                    $t->timestampTz('claim_until')->nullable();
                    $t->integer('publication_attempts')->default(0);
                    $t->string('last_error')->nullable();
                    $t->unique(['document_id', 'generation']);
                    $t->index(['published_at', 'available_at']);
                } elseif ($table === 'emission_attempts') {
                    $t->integer('sequence');
                    $t->timestampTz('finished_at')->nullable();
                    $t->string('outcome')->nullable();
                    $t->unique(['document_id', 'sequence']);
                } else {
                    $t->string('fingerprint', 64);
                    $t->integer('calls')->default(0);
                    $t->string('scenario');
                    $t->jsonb('result')->nullable();
                }
            });
        }
        Schema::create('simulation_scenarios', function (Blueprint $t): void {
            $t->foreignUuid('document_id')->primary()->constrained('documents')->cascadeOnDelete();
            $t->string('scenario');
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_numbered_document() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.number IS NOT NULL THEN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Numbered documents cannot be deleted'; END IF;
  IF (to_jsonb(OLD) - ARRAY['status','version','updated_at','attempt_count','active_attempt_id','lease_until','next_attempt_at','result_code','result_message','failure_kind','can_retry']) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['status','version','updated_at','attempt_count','active_attempt_id','lease_until','next_attempt_at','result_code','result_message','failure_kind','can_retry']) THEN
   RAISE EXCEPTION 'Fiscal snapshot is immutable';
  END IF;
  IF NEW.status IS DISTINCT FROM OLD.status AND NOT ((OLD.status='queued' AND NEW.status='sending') OR (OLD.status='sending' AND NEW.status IN ('accepted','rejected','failed')) OR (OLD.status='failed' AND NEW.status='queued')) THEN RAISE EXCEPTION 'Invalid emission transition'; END IF;
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER documents_immutable BEFORE UPDATE OR DELETE ON documents FOR EACH ROW EXECUTE FUNCTION protect_numbered_document();
CREATE OR REPLACE FUNCTION protect_numbered_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_number bigint;
BEGIN
 IF TG_OP <> 'INSERT' THEN
  SELECT number INTO parent_number FROM documents WHERE id=OLD.document_id FOR UPDATE;
  IF parent_number IS NOT NULL THEN RAISE EXCEPTION 'Numbered document lines are immutable'; END IF;
 END IF;
 IF TG_OP <> 'DELETE' THEN
  SELECT number INTO parent_number FROM documents WHERE id=NEW.document_id FOR UPDATE;
  IF parent_number IS NOT NULL THEN RAISE EXCEPTION 'Numbered document lines are immutable'; END IF;
  RETURN NEW;
 END IF;
 RETURN OLD;
END $$;
CREATE TRIGGER document_items_immutable BEFORE INSERT OR UPDATE OR DELETE ON document_items FOR EACH ROW EXECUTE FUNCTION protect_numbered_line();
SQL);
    }

    public function down(): void
    {
        if (DB::table('documents')->whereNotIn('status', ['draft', 'issued'])->exists()) {
            throw new RuntimeException('Rollback refused: preserve registered emissions and their audit trail.');
        }
        DB::unprepared('DROP TRIGGER document_items_immutable ON document_items; DROP FUNCTION protect_numbered_line(); DROP TRIGGER documents_immutable ON documents; DROP FUNCTION protect_numbered_document();');
        Schema::dropIfExists('simulation_scenarios');
        foreach (['simulated_submissions', 'emission_attempts', 'emission_outbox', 'document_events', 'emission_requests'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_values_check');
        Schema::table('documents', function (Blueprint $t): void {
            $t->dropUnique('documents_fiscal_number_unique');
            $t->dropColumn(['fiscal_snapshot', 'submission_id', 'series_code', 'attempt_count', 'active_attempt_id', 'lease_until', 'next_attempt_at', 'result_code', 'result_message', 'failure_kind', 'can_retry']);
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_values_check CHECK (doc_type IN ('01','03') AND currency IN ('PEN','USD') AND status IN ('draft','issued') AND version > 0 AND (status <> 'draft' OR (number IS NULL AND issued_at IS NULL)))");
    }
};
