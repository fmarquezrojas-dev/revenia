<?php

namespace App\Console\Commands;

use App\Actions\Demo\LocalDemoDataset;
use App\Actions\Issuance\EmissionOutbox;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadLocalDemo extends Command
{
    protected $signature = 'demo:load-local {company : UUID exacto de la empresa} {--actor= : UUID del owner existente} {--dry-run} {--small : Dataset reducido de aislamiento}';

    protected $description = 'Carga local persistente y repetible; conserva cambios manuales y credenciales.';

    public function handle(LocalDemoDataset $dataset): int
    {
        $connection = DB::selectOne('SELECT current_database() AS database_name, current_user AS username');
        if (! app()->environment('local') || config('database.default') !== 'pgsql' || $connection->database_name !== 'facturly' || $connection->username !== 'facturly' || config('database.connections.pgsql.host') !== 'db') {
            $this->error('Se requiere local / PostgreSQL db / facturly / facturly. No se permite testing ni remoto.');

            return self::FAILURE;
        }
        $actor = User::where('company_id', $this->argument('company'))->whereKey($this->option('actor'))->first();
        if (! $actor) {
            $this->error('El actor no pertenece al UUID de empresa indicado.');

            return self::FAILURE;
        }
        try {
            $result = $dataset->load($actor, (bool) $this->option('small'), (bool) $this->option('dry-run'));
            if (! $this->option('dry-run')) {
                app(EmissionOutbox::class)->publish();
            }
            $this->line(json_encode(['connection' => $connection, 'company' => $actor->company->only(['id', 'legal_name', 'trade_name'])] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e instanceof \RuntimeException ? $e->getMessage() : 'Carga interrumpida; revisar validaciones sin alterar los datos existentes.');

            return self::FAILURE;
        }
    }
}
