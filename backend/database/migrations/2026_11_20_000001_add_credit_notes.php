<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function documentCheck(string $types): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_values_check');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_values_check CHECK (
            doc_type IN ($types) AND currency IN ('PEN','USD') AND version > 0
            AND status IN ('draft','issued','queued','sending','accepted','rejected','failed')
            AND (status <> 'draft' OR (number IS NULL AND issued_at IS NULL))
            AND (status IN ('draft','issued') OR (number BETWEEN 1 AND 99999999 AND number IS NOT NULL
                 AND issued_at IS NOT NULL AND submission_id IS NOT NULL AND fiscal_snapshot IS NOT NULL AND series_code IS NOT NULL)))");
    }

    public function up(): void
    {
        $this->documentCheck("'01','03','07'");
        DB::unprepared(<<<'SQL'
            ALTER TABLE documents
                ADD COLUMN source_document_id uuid,
                ADD COLUMN credit_reason varchar(250),
                ADD UNIQUE (company_id, id, currency),
                ADD UNIQUE (company_id, id, source_document_id),
                ADD FOREIGN KEY (company_id, source_document_id, currency) REFERENCES documents(company_id, id, currency),
                ADD CHECK ((doc_type = '07' AND source_document_id IS NOT NULL AND source_document_id <> id
                    AND credit_reason IS NOT NULL AND length(trim(credit_reason)) > 0)
                    OR (doc_type <> '07' AND source_document_id IS NULL AND credit_reason IS NULL));
            CREATE INDEX documents_credit_source ON documents(company_id, source_document_id) WHERE source_document_id IS NOT NULL;
            ALTER TABLE document_items ADD CONSTRAINT credit_item_identity UNIQUE (company_id, document_id, id);
            CREATE TABLE credit_note_lines (
                company_id uuid NOT NULL,
                note_id uuid NOT NULL,
                source_document_id uuid NOT NULL,
                source_item_id uuid NOT NULL,
                note_item_id uuid PRIMARY KEY,
                state varchar(10) NOT NULL DEFAULT 'draft' CHECK (state IN ('draft','reserved','applied','released')),
                UNIQUE (note_id, source_item_id),
                FOREIGN KEY (company_id, note_id, source_document_id) REFERENCES documents(company_id, id, source_document_id) ON DELETE CASCADE,
                FOREIGN KEY (company_id, source_document_id, source_item_id) REFERENCES document_items(company_id, document_id, id),
                FOREIGN KEY (company_id, note_id, note_item_id) REFERENCES document_items(company_id, document_id, id) ON DELETE CASCADE
            );
            CREATE UNIQUE INDEX credit_once_per_line ON credit_note_lines(source_item_id) WHERE state IN ('reserved','applied');
            CREATE INDEX credit_source_state ON credit_note_lines(company_id, source_document_id, state);
            SQL);
    }

    public function down(): void
    {
        if (DB::table('documents')->where('doc_type', '07')->exists()) {
            throw new RuntimeException('Existen notas de crédito. Conservar los documentos y su historial.');
        }
        DB::statement('DROP TABLE credit_note_lines');
        DB::statement('ALTER TABLE document_items DROP CONSTRAINT credit_item_identity');
        DB::statement('ALTER TABLE documents DROP COLUMN source_document_id, DROP COLUMN credit_reason, DROP CONSTRAINT documents_company_id_id_currency_key');
        $this->documentCheck("'01','03'");
    }
};
