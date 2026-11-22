<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE bank_accounts (
                id uuid PRIMARY KEY, company_id uuid NOT NULL REFERENCES companies(id), alias varchar(80) NOT NULL,
                institution varchar(100) NOT NULL, currency varchar(3) NOT NULL CHECK(currency IN ('PEN','USD')),
                active boolean NOT NULL DEFAULT true, revision integer NOT NULL DEFAULT 1,
                UNIQUE(company_id,id,currency)
            );
            CREATE UNIQUE INDEX bank_account_alias ON bank_accounts(company_id,lower(alias));
            CREATE TABLE bank_receipts (
                id uuid PRIMARY KEY, company_id uuid NOT NULL, account_id uuid NOT NULL, customer_id uuid, currency varchar(3) NOT NULL,
                bank_date date NOT NULL, method varchar(10) NOT NULL CHECK(method IN ('transfer','deposit')),
                origin varchar(16) NOT NULL CHECK(origin IN ('customer','mp_settlement')), reference varchar(100) NOT NULL,
                reference_key char(64) NOT NULL, note varchar(600), amount_cents bigint NOT NULL CHECK(amount_cents BETWEEN 1 AND 9007199254740991),
                applied_cents bigint NOT NULL DEFAULT 0 CHECK(applied_cents BETWEEN 0 AND amount_cents),
                revision integer NOT NULL DEFAULT 1, fingerprint char(64) NOT NULL,
                recorded_by uuid NOT NULL, recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(), confirmed_by uuid, confirmed_at timestamptz,
                UNIQUE(company_id,id), UNIQUE(company_id,id,currency), UNIQUE(company_id,account_id,reference_key),
                CHECK ((confirmed_at IS NULL) = (confirmed_by IS NULL)),
                CHECK (origin='customer' OR (applied_cents=0 AND customer_id IS NULL)),
                CHECK (applied_cents=0 OR (confirmed_at IS NOT NULL AND customer_id IS NOT NULL)),
                FOREIGN KEY(company_id,account_id,currency) REFERENCES bank_accounts(company_id,id,currency),
                FOREIGN KEY(company_id,customer_id) REFERENCES customers(company_id,id),
                FOREIGN KEY(company_id,recorded_by) REFERENCES users(company_id,id),
                FOREIGN KEY(company_id,confirmed_by) REFERENCES users(company_id,id)
            );
            CREATE INDEX bank_receipt_inbox ON bank_receipts(company_id,currency,bank_date DESC,id);
            CREATE INDEX bank_customer_history ON bank_receipts(company_id,customer_id,bank_date DESC,id);
            CREATE TABLE bank_operations (
                company_id uuid NOT NULL, request_id uuid NOT NULL, receipt_id uuid NOT NULL, actor_id uuid NOT NULL,
                action varchar(12) NOT NULL CHECK(action IN ('register','match','confirm','identify','apply','reverse')),
                fingerprint char(64) NOT NULL, details jsonb NOT NULL, recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                PRIMARY KEY(company_id,request_id), FOREIGN KEY(company_id,receipt_id) REFERENCES bank_receipts(company_id,id),
                FOREIGN KEY(company_id,actor_id) REFERENCES users(company_id,id)
            );
            CREATE INDEX bank_operations_history ON bank_operations(company_id,receipt_id,recorded_at,request_id);
            ALTER TABLE documents ADD CONSTRAINT bank_document_customer UNIQUE(company_id,id,customer_id,currency);
            CREATE TABLE bank_applications (
                id uuid PRIMARY KEY, company_id uuid NOT NULL, receipt_id uuid NOT NULL, document_id uuid NOT NULL, customer_id uuid NOT NULL,
                currency varchar(3) NOT NULL, amount_cents bigint NOT NULL CHECK(amount_cents BETWEEN 1 AND 9007199254740991),
                applied_by uuid NOT NULL, applied_at timestamptz NOT NULL DEFAULT clock_timestamp(), batch_id uuid NOT NULL,
                reversed_by uuid, reversed_at timestamptz, reason varchar(600),
                CHECK((reversed_at IS NULL) = (reversed_by IS NULL) AND (reversed_at IS NULL) = (reason IS NULL)),
                UNIQUE(company_id,batch_id,document_id),
                FOREIGN KEY(company_id,receipt_id,currency) REFERENCES bank_receipts(company_id,id,currency),
                FOREIGN KEY(company_id,document_id,customer_id,currency) REFERENCES documents(company_id,id,customer_id,currency),
                FOREIGN KEY(company_id,applied_by) REFERENCES users(company_id,id),
                FOREIGN KEY(company_id,reversed_by) REFERENCES users(company_id,id)
            );
            CREATE INDEX bank_app_document ON bank_applications(company_id,document_id,applied_at,id);
            CREATE INDEX bank_app_receipt ON bank_applications(company_id,receipt_id,applied_at,id);
            CREATE INDEX bank_app_activity ON bank_applications(company_id,currency,applied_at) WHERE reversed_at IS NULL;
            CREATE OR REPLACE FUNCTION bank_apply_balance() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' AND NEW.reversed_at IS NULL THEN
                    UPDATE bank_receipts SET applied_cents=applied_cents+NEW.amount_cents
                      WHERE company_id=NEW.company_id AND id=NEW.receipt_id AND currency=NEW.currency
                        AND customer_id=NEW.customer_id AND origin='customer' AND confirmed_at IS NOT NULL
                        AND amount_cents-applied_cents >= NEW.amount_cents;
                ELSIF TG_OP = 'UPDATE' AND OLD.reversed_at IS NULL AND NEW.reversed_at IS NOT NULL
                    AND (to_jsonb(NEW)-ARRAY['reason','reversed_by','reversed_at']) = (to_jsonb(OLD)-ARRAY['reason','reversed_by','reversed_at']) THEN
                    UPDATE bank_receipts SET applied_cents=applied_cents-OLD.amount_cents WHERE company_id=OLD.company_id AND id=OLD.receipt_id;
                ELSE
                    RAISE EXCEPTION 'Immutable bank application';
                END IF;
                IF NOT FOUND THEN RAISE EXCEPTION 'Bank receipt unavailable'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER bank_application_balance BEFORE INSERT OR UPDATE OR DELETE ON bank_applications FOR EACH ROW EXECUTE FUNCTION bank_apply_balance();

            CREATE VIEW document_cash_entries AS
              SELECT id::text, company_id, document_id, currency, status, applied_cents, refunded_cents, needs_review, confirmed_at,
                     confirmed_at AS funds_confirmed_at, NULL::date AS bank_date
                FROM payments WHERE document_id IS NOT NULL AND subscription_id IS NULL
              UNION ALL
              SELECT 'bank:'||a.id, a.company_id, a.document_id, a.currency, 'approved', a.amount_cents, 0, false, a.applied_at,
                     r.confirmed_at, r.bank_date
                FROM bank_applications a JOIN bank_receipts r ON (r.company_id,r.id)=(a.company_id,a.receipt_id)
                WHERE a.reversed_at IS NULL AND r.confirmed_at IS NOT NULL AND r.origin='customer';
            ALTER TABLE collection_promises ADD COLUMN terms_reviewed_at timestamptz;
            SQL);
    }

    public function down(): void
    {
        if (DB::table('bank_receipts')->exists() || DB::table('bank_accounts')->exists()) {
            throw new RuntimeException('El historial de tesorería debe conservarse.');
        }
        DB::unprepared('DROP VIEW document_cash_entries; DROP TABLE bank_applications, bank_operations, bank_receipts, bank_accounts;
            DROP FUNCTION bank_apply_balance(); ALTER TABLE documents DROP CONSTRAINT bank_document_customer;
            ALTER TABLE collection_promises DROP COLUMN terms_reviewed_at;');
    }
};
