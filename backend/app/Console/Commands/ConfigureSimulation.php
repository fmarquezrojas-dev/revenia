<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\SimulatedTaxProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ConfigureSimulation extends Command
{
    protected $signature = 'issuance:scenario {document} {scenario}';

    protected $description = 'Configure a deterministic simulator scenario (explicit local/testing opt-in only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || ! config('issuance.demo_controls')) {
            $this->error('Requires local/testing and SIMULATION_DEMO_CONTROLS=true.');

            return self::FAILURE;
        }
        if (! in_array($this->argument('scenario'), SimulatedTaxProvider::SCENARIOS, true)) {
            $this->error('Unknown scenario.');

            return self::FAILURE;
        }

        return DB::transaction(function (): int {
            $doc = Document::whereKey($this->argument('document'))->lockForUpdate()->firstOrFail();
            if ($doc->status !== 'draft') {
                $this->error('Select a draft before issuance.');

                return self::FAILURE;
            }
            DB::table('simulation_scenarios')->updateOrInsert(['document_id' => $doc->id], ['scenario' => $this->argument('scenario')]);
            $this->info('Scenario configured.');

            return self::SUCCESS;
        });
    }
}
