<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payments ALTER COLUMN confirmed_at TYPE timestamptz(6);
            CREATE TABLE collection_followups (
                company_id uuid NOT NULL, document_id uuid NOT NULL, assignee_id uuid,
                revision integer NOT NULL DEFAULT 0 CHECK (revision >= 0), next_action_on date,
                PRIMARY KEY(company_id, document_id),
                FOREIGN KEY(company_id,document_id) REFERENCES documents(company_id,id),
                FOREIGN KEY(company_id,assignee_id) REFERENCES users(company_id,id)
            );
            CREATE INDEX followup_agenda ON collection_followups(company_id,assignee_id,next_action_on,document_id);
            CREATE TABLE collection_promises (
                id uuid PRIMARY KEY, company_id uuid NOT NULL, document_id uuid NOT NULL, currency varchar(3) NOT NULL,
                amount_cents bigint NOT NULL CHECK(amount_cents BETWEEN 1 AND 9007199254740991),
                due_on date NOT NULL, opened_at timestamptz NOT NULL, closed_at timestamptz,
                status varchar(12) NOT NULL DEFAULT 'active' CHECK(status IN ('active','cancelled','fulfilled')),
                credits_at_terms bigint NOT NULL, preexisting jsonb NOT NULL, evidence jsonb NOT NULL DEFAULT '{}',
                CHECK ((status='active') = (closed_at IS NULL)),
                UNIQUE(company_id,document_id,id),
                FOREIGN KEY(company_id,document_id) REFERENCES collection_followups(company_id,document_id),
                FOREIGN KEY(company_id,document_id,currency) REFERENCES documents(company_id,id,currency)
            );
            CREATE UNIQUE INDEX collection_one_active_promise ON collection_promises(company_id,document_id) WHERE status='active';
            CREATE INDEX collection_promise_dates ON collection_promises(company_id,due_on,document_id) WHERE status='active';
            CREATE INDEX collection_payment_evidence ON payments(company_id,document_id,currency,confirmed_at,id)
                WHERE document_id IS NOT NULL AND subscription_id IS NULL AND confirmed_at IS NOT NULL;
            CREATE TABLE collection_followup_events (
                id uuid PRIMARY KEY, company_id uuid NOT NULL, document_id uuid NOT NULL, actor_id uuid NOT NULL,
                revision integer NOT NULL, kind varchar(16) NOT NULL
                    CHECK(kind IN ('assign','contact','correct','promise','reschedule','cancel','fulfill')),
                corrects_id uuid, promise_id uuid, details jsonb NOT NULL, recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(company_id,document_id,revision), UNIQUE(company_id,document_id,id),
                FOREIGN KEY(company_id,document_id) REFERENCES collection_followups(company_id,document_id),
                FOREIGN KEY(company_id,actor_id) REFERENCES users(company_id,id),
                FOREIGN KEY(company_id,document_id,corrects_id) REFERENCES collection_followup_events(company_id,document_id,id),
                FOREIGN KEY(company_id,document_id,promise_id) REFERENCES collection_promises(company_id,document_id,id)
            );
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE collection_followup_events, collection_promises, collection_followups');
        DB::statement('DROP INDEX collection_payment_evidence');
        // Keep the precision of immutable confirmation timestamps already recorded.
    }
};
