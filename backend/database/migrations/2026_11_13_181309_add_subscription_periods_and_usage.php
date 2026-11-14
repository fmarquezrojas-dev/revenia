<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t): void {
            $t->string('code', 40)->primary();
            $t->string('name');
            $t->text('description');
            $t->bigInteger('price_cents');
            $t->string('currency', 3);
            $t->integer('emission_limit');
            $t->boolean('available')->default(true);
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE plans ADD CHECK(price_cents>0 AND price_cents<=9007199254740991 AND currency='PEN' AND emission_limit>0)");
        foreach ([['demo-basic', 'Basico', 1900, 50], ['demo-plus', 'Plus', 4900, 200]] as [$code,$name,$price,$limit]) {
            DB::table('plans')->insert(['code' => $code, 'name' => $name, 'description' => 'Plan ficticio de demostracion. Un mes, sin renovacion automatica.', 'price_cents' => $price, 'currency' => 'PEN', 'emission_limit' => $limit, 'available' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('subscriptions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->string('plan_code', 40);
            $t->string('plan_name');
            $t->bigInteger('price_cents');
            $t->string('currency', 3);
            $t->integer('emission_limit');
            $t->integer('duration_months')->default(1);
            $t->string('status', 20)->default('pending');
            $t->string('source', 20)->default('checkout');
            $t->timestampTz('starts_at')->nullable();
            $t->timestampTz('ends_at')->nullable();
            $t->timestampTz('anchor_at')->nullable();
            $t->integer('period_index')->nullable();
            $t->uuid('predecessor_id')->nullable();
            $t->string('review_code', 80)->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'id']);
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('plan_code')->references('code')->on('plans')->restrictOnDelete();
            $t->foreign(['company_id', 'predecessor_id'])->references(['company_id', 'id'])->on('subscriptions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE subscriptions ADD CHECK(status IN ('pending','paid','review','cancelled') AND source IN ('checkout','demo') AND price_cents>0 AND emission_limit>0 AND duration_months=1 AND currency='PEN' AND (status<>'paid' OR starts_at IS NOT NULL) AND ((starts_at IS NULL AND ends_at IS NULL AND anchor_at IS NULL AND period_index IS NULL) OR (starts_at IS NOT NULL AND ends_at IS NOT NULL AND ends_at>starts_at AND anchor_at IS NOT NULL AND period_index>=0)))");
        DB::statement("CREATE UNIQUE INDEX subscriptions_one_pending ON subscriptions(company_id) WHERE status='pending'");
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_no_overlap EXCLUDE USING gist (company_id WITH =, tstzrange(starts_at,ends_at,'[)') WITH &&) WHERE (starts_at IS NOT NULL)");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_subscription_terms() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF ROW(NEW.company_id,NEW.plan_code,NEW.plan_name,NEW.price_cents,NEW.currency,NEW.emission_limit,NEW.duration_months,NEW.source,NEW.predecessor_id) IS DISTINCT FROM ROW(OLD.company_id,OLD.plan_code,OLD.plan_name,OLD.price_cents,OLD.currency,OLD.emission_limit,OLD.duration_months,OLD.source,OLD.predecessor_id) THEN RAISE EXCEPTION 'Contracted terms are immutable'; END IF;
 IF OLD.starts_at IS NOT NULL AND ROW(NEW.starts_at,NEW.ends_at,NEW.anchor_at,NEW.period_index) IS DISTINCT FROM ROW(OLD.starts_at,OLD.ends_at,OLD.anchor_at,OLD.period_index) THEN RAISE EXCEPTION 'Existing periods are immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER subscription_terms_immutable BEFORE UPDATE ON subscriptions FOR EACH ROW EXECUTE FUNCTION protect_subscription_terms();
SQL);
        Schema::create('company_usage', function (Blueprint $t): void {
            $t->uuid('subscription_id')->primary();
            $t->uuid('company_id');
            $t->bigInteger('consumed')->default(0);
            $t->timestampsTz();
            $t->unique(['company_id', 'subscription_id']);
            $t->foreign(['company_id', 'subscription_id'])->references(['company_id', 'id'])->on('subscriptions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE company_usage ADD CHECK(consumed>=0)');
        Schema::create('emission_consumptions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('subscription_id');
            $t->uuid('document_id')->unique();
            $t->timestampTz('consumed_at');
            $t->boolean('historical')->default(false);
            $t->foreign(['company_id', 'subscription_id'])->references(['company_id', 'subscription_id'])->on('company_usage')->restrictOnDelete();
            $t->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
        });
        Schema::create('subscription_demo_grants', function (Blueprint $t): void {
            $t->uuid('company_id')->primary();
            $t->timestampTz('created_at');
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });
        foreach (['payment_orders', 'payments', 'payment_requests'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->uuid('document_id')->nullable()->change();
                $t->uuid('subscription_id')->nullable();
                $t->foreign(['company_id', 'subscription_id'])->references(['company_id', 'id'])->on('subscriptions')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_one_destination CHECK ((document_id IS NOT NULL)::integer + (subscription_id IS NOT NULL)::integer = 1)");
        }
        Schema::table('payment_orders', function (Blueprint $t): void {
            $t->unique(['company_id', 'subscription_id', 'id']);
            $t->unique(['company_id', 'id']);
            $t->unique('subscription_id');
        });
        Schema::table('payments', function (Blueprint $t): void {
            $t->foreign(['company_id', 'subscription_id', 'order_id'])->references(['company_id', 'subscription_id', 'id'])->on('payment_orders')->restrictOnDelete();
        });
        Schema::table('payment_audit', fn (Blueprint $t) => $t->uuid('subscription_id')->nullable());
    }

    public function down(): void
    {
        throw new RuntimeException('Preservar contratos, consumos y destinos financieros. Requiere migracion de retorno explicita.');
    }
};
