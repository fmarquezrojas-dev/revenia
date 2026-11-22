<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE odoo_connections (
                company_id uuid PRIMARY KEY REFERENCES companies(id), endpoint varchar(80) NOT NULL,
                remote_company integer NOT NULL CHECK(remote_company>0), login varchar(255) NOT NULL, secret text NOT NULL,
                revision uuid NOT NULL, destination varchar(64) NOT NULL, database_uuid uuid NOT NULL, remote_name varchar(255) NOT NULL,
                enabled boolean NOT NULL DEFAULT false, verified_at timestamptz NOT NULL, updated_at timestamptz NOT NULL
            );
            CREATE TABLE odoo_mappings (
                company_id uuid NOT NULL REFERENCES companies(id), destination varchar(64) NOT NULL,
                kind varchar(16) NOT NULL, source varchar(80) NOT NULL, remote_id integer NOT NULL CHECK(remote_id>0),
                PRIMARY KEY(company_id,destination,kind,source)
            );
            CREATE TABLE odoo_transfers (
                id uuid PRIMARY KEY, company_id uuid NOT NULL, document_id uuid NOT NULL, destination varchar(64) NOT NULL,
                destination_name varchar(255) NOT NULL, status varchar(16) NOT NULL,
                attempts integer NOT NULL DEFAULT 0, revision integer NOT NULL DEFAULT 1, may_exist boolean NOT NULL DEFAULT false,
                payload jsonb, token uuid, lease_until timestamptz, published_at timestamptz, available_at timestamptz,
                external_id integer, accounting_state varchar(16), error_code varchar(60), first_error_at timestamptz,
                created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
                FOREIGN KEY(company_id,document_id) REFERENCES documents(company_id,id),
                UNIQUE(company_id,document_id), UNIQUE(company_id,id),
                CHECK(status IN ('queued','processing','retry','dependency','review','uncertain','done')),
                CHECK(status<>'done' OR (external_id IS NOT NULL AND accounting_state='draft'))
            );
            CREATE UNIQUE INDEX odoo_external_reference ON odoo_transfers(company_id,destination,external_id) WHERE external_id IS NOT NULL;
            CREATE INDEX odoo_outbox_due ON odoo_transfers(status,available_at,published_at);
            CREATE INDEX odoo_incident_list ON odoo_transfers(company_id,status,updated_at);
            CREATE TABLE odoo_events (
                id uuid PRIMARY KEY, company_id uuid NOT NULL, transfer_id uuid NOT NULL,
                destination varchar(64) NOT NULL, destination_name varchar(255) NOT NULL,
                actor_id uuid, event varchar(40) NOT NULL, code varchar(60), created_at timestamptz NOT NULL,
                FOREIGN KEY(company_id,transfer_id) REFERENCES odoo_transfers(company_id,id),
                FOREIGN KEY(company_id,actor_id) REFERENCES users(company_id,id)
            );
            CREATE INDEX odoo_event_history ON odoo_events(company_id,transfer_id,created_at,id);
            SQL);
    }

    public function down(): void
    {
        if (DB::table('odoo_connections')->exists() || DB::table('odoo_transfers')->exists()) {
            throw new RuntimeException('Conserva la configuración y las referencias de Odoo.');
        }
        DB::unprepared('DROP TABLE odoo_events,odoo_transfers,odoo_mappings,odoo_connections;');
    }
};
