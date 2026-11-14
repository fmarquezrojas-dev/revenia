<?php

namespace App\Console\Commands;

use App\Actions\Subscriptions\Subscriptions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SubscriptionMaintenance extends Command
{
    protected $signature = 'subscriptions:maintain {--company=} {--grant-demo} {--sync-catalog} {--limit=100}';

    protected $description = 'Transicion demo explicita o auditoria de consumo de solo lectura.';

    public function handle(Subscriptions $s): int
    {
        if ($this->option('sync-catalog')) {
            DB::transaction(function (): void {
                foreach (config('subscriptions.catalog', []) as $plan) {
                    Validator::make($plan, ['code' => 'required|regex:/\A[a-z0-9-]{1,40}\z/', 'name' => 'required|string|max:255', 'description' => 'required|string', 'price_cents' => 'required|integer|min:1|max:9007199254740991', 'currency' => 'required|in:PEN', 'emission_limit' => 'required|integer|min:1|max:2147483647', 'available' => 'required|boolean'])->validate();
                    DB::table('plans')->upsert([$plan + ['created_at' => now(), 'updated_at' => now()]], ['code'], ['name', 'description', 'price_cents', 'currency', 'emission_limit', 'available', 'updated_at']);
                }
            });
            $this->info('Catalogo sincronizado; contratos existentes intactos.');

            return self::SUCCESS;
        }
        $company = $this->option('company');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (($company && ! Str::isUuid($company)) || ! $limit || $limit < 1 || $limit > 1000) {
            $this->error('Empresa/limite invalidos.');

            return self::FAILURE;
        }try {
            if ($this->option('grant-demo')) {
                if (! $company) {
                    $this->error('Indica --company explicitamente.');

                    return self::FAILURE;
                }$this->line(json_encode($s->grantDemo($company), JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }$differences = $s->audit($company, $limit);
            $this->line(json_encode(['differences' => $differences, 'limit' => $limit], JSON_THROW_ON_ERROR));

            return $differences ? self::FAILURE : self::SUCCESS;
        } catch (\DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
