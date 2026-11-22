<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE merchant_connections (
                id uuid PRIMARY KEY, company_id uuid NOT NULL REFERENCES companies(id),
                collector_id varchar(25) NOT NULL UNIQUE, nickname varchar(160), active boolean NOT NULL, verification_error varchar(80),
                access_token text NOT NULL, webhook_secret text NOT NULL, verified_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL, UNIQUE(company_id, id, collector_id)
            );
            CREATE UNIQUE INDEX merchant_company_active ON merchant_connections(company_id) WHERE active;
            ALTER TABLE payment_orders ADD COLUMN receiver_kind varchar(10) NOT NULL DEFAULT 'legacy',
                ADD COLUMN environment varchar(10), ADD COLUMN collector_id varchar(25), ADD COLUMN connection_id uuid,
                ADD FOREIGN KEY (company_id, connection_id, collector_id) REFERENCES merchant_connections(company_id, id, collector_id),
                ADD CHECK ((receiver_kind='legacy' AND environment IS NULL AND collector_id IS NULL AND connection_id IS NULL) OR
                    (environment IS NOT NULL AND environment='sandbox' AND collector_id IS NOT NULL AND
                    ((receiver_kind='merchant' AND document_id IS NOT NULL AND connection_id IS NOT NULL) OR
                     (receiver_kind='platform' AND subscription_id IS NOT NULL AND connection_id IS NULL))));
            CREATE INDEX payment_orders_platform_receiver ON payment_orders(collector_id) WHERE receiver_kind = 'platform';
            CREATE OR REPLACE FUNCTION protect_order_receiver() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.provider='mercadopago' AND NEW.receiver_kind='legacy' THEN
                        RAISE EXCEPTION 'payment receiver required';
                    END IF;
                    RETURN NEW;
                END IF;
                IF ROW(NEW.id,NEW.provider,NEW.amount_cents,NEW.currency,NEW.company_id,NEW.document_id,NEW.subscription_id,NEW.receiver_kind,NEW.environment,NEW.collector_id,NEW.connection_id)
                    IS DISTINCT FROM ROW(OLD.id,OLD.provider,OLD.amount_cents,OLD.currency,OLD.company_id,OLD.document_id,OLD.subscription_id,OLD.receiver_kind,OLD.environment,OLD.collector_id,OLD.connection_id)
                THEN RAISE EXCEPTION 'immutable payment order receiver'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER payment_order_receiver BEFORE INSERT OR UPDATE ON payment_orders FOR EACH ROW EXECUTE FUNCTION protect_order_receiver();
            ALTER TABLE payment_work ADD COLUMN webhook_scope varchar(10), ADD COLUMN webhook_collector_id varchar(25), ADD COLUMN connection_id uuid REFERENCES merchant_connections(id);
            ALTER TABLE payment_audit ADD COLUMN actor_id uuid REFERENCES users(id), ADD COLUMN connection_id uuid REFERENCES merchant_connections(id);
            SQL);
    }

    public function down(): void
    {
        if (DB::table('merchant_connections')->exists() || DB::table('payment_orders')->where('receiver_kind', '<>', 'legacy')->exists()
            || DB::table('payment_work')->whereNotNull('webhook_scope')->exists() || DB::table('payment_audit')->whereNotNull('actor_id')->exists()) {
            throw new RuntimeException('Conserva las conexiones y el historial de receptores.');
        }
        DB::unprepared('DROP TRIGGER payment_order_receiver ON payment_orders; DROP FUNCTION protect_order_receiver();
            ALTER TABLE payment_work DROP COLUMN webhook_scope, DROP COLUMN webhook_collector_id, DROP COLUMN connection_id;
            ALTER TABLE payment_audit DROP COLUMN actor_id, DROP COLUMN connection_id;
            ALTER TABLE payment_orders DROP COLUMN receiver_kind, DROP COLUMN environment, DROP COLUMN collector_id, DROP COLUMN connection_id;
            DROP TABLE merchant_connections;');
    }
};
