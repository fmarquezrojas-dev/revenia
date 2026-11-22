<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE incident_follow_ups (
                id uuid PRIMARY KEY, company_id uuid NOT NULL, document_id uuid NOT NULL,
                process varchar(16) NOT NULL, operation_id uuid NOT NULL, actor_id uuid NOT NULL,
                action varchar(24) NOT NULL, request_key uuid NOT NULL, revision varchar(100) NOT NULL,
                outcome varchar(32) NOT NULL, http_status smallint NOT NULL, created_at timestamptz NOT NULL,
                FOREIGN KEY (company_id, document_id) REFERENCES documents(company_id,id),
                FOREIGN KEY (company_id, actor_id) REFERENCES users(company_id,id),
                UNIQUE(company_id, request_key)
            );
            CREATE INDEX incident_follow_up_history ON incident_follow_ups(company_id,process,operation_id,created_at,id);
            CREATE INDEX incident_document_status ON documents(company_id,status,updated_at) WHERE submission_id IS NOT NULL;
            CREATE INDEX incident_pdf_status ON document_pdfs(company_id,status,updated_at);
            CREATE INDEX incident_delivery_sequence ON document_email_deliveries(company_id,document_id,sequence DESC);
            CREATE INDEX incident_delivery_attempt_history ON document_delivery_attempts(company_id,document_id,kind,created_at);
            CREATE INDEX incident_payment_audit_history ON payment_audit(company_id,order_id,created_at) WHERE order_id IS NOT NULL;
            CREATE INDEX incident_unlinked_failure ON payment_audit(company_id,work_id,created_at) WHERE order_id IS NULL AND event='work_failed';
            CREATE INDEX incident_payment_status ON payments(company_id,order_id,status) WHERE document_id IS NOT NULL;
            CREATE INDEX incident_merchant_notification ON payment_work(company_id,connection_id,payment_id,created_at) WHERE webhook_scope='merchant';
            CREATE TABLE incident_queue_events (
                id uuid PRIMARY KEY, publication_id uuid NOT NULL REFERENCES emission_outbox(id),
                attempt integer NOT NULL, event varchar(24) NOT NULL, occurred_at timestamptz NOT NULL,
                UNIQUE(publication_id,attempt,event)
            );
            ALTER TABLE document_delivery_requests ADD COLUMN actor_id uuid,
                ADD FOREIGN KEY (company_id,actor_id) REFERENCES users(company_id,id);
            SQL);
    }

    public function down(): void
    {
        if (DB::table('incident_follow_ups')->exists() || DB::table('incident_queue_events')->exists()
            || DB::table('document_delivery_requests')->whereNotNull('actor_id')->exists()) {
            throw new RuntimeException('Conserva el historial de seguimiento de incidencias.');
        }
        DB::unprepared('DROP TABLE incident_follow_ups;
            DROP INDEX incident_document_status, incident_pdf_status, incident_delivery_sequence,
                incident_delivery_attempt_history, incident_payment_audit_history, incident_unlinked_failure, incident_payment_status, incident_merchant_notification;
            DROP TABLE incident_queue_events;
            ALTER TABLE document_delivery_requests DROP COLUMN actor_id;');
    }
};
