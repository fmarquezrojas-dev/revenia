<?php

namespace App\Actions\Demo;

use App\Actions\Customers\SaveCustomer;
use App\Actions\Documents\DraftDocuments;
use App\Actions\Issuance\RequestIssuance;
use App\Actions\Products\SaveProduct;
use App\Actions\Series\SaveDocumentSeries;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LocalDemoDataset
{
    public const DATASET = 'local-demo-2026-v1';

    private array $counts = [];

    public function load(User $actor, bool $small = false, bool $dryRun = false): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Dataset exclusivamente local.');
        }
        Gate::forUser($actor)->authorize('create', DocumentSeries::class);
        if (! $actor->is_active || ! $actor->company->is_active) {
            throw new \RuntimeException('Cuenta y empresa deben estar activas.');
        }
        foreach (['issuer', 'accountant'] as $role) {
            if (! User::where('company_id', $actor->company_id)->where('role', $role)->where('is_active', true)->exists()) {
                throw new \RuntimeException('Prepara una cuenta activa del rol '.$role.' mediante Usuarios; no se cambian claves existentes.');
            }
        }
        if (! $small && ! config('issuance.demo_controls')) {
            throw new \RuntimeException('Activa SIMULATION_DEMO_CONTROLS en el entorno local y reinicia worker/scheduler antes de cargar escenarios.');
        }
        $this->counts = [];
        $models = ['customers' => Customer::class, 'products' => Product::class, 'series' => DocumentSeries::class, 'documents' => Document::class, 'users' => User::class];
        $before = [];
        foreach ($models as $kind => $model) {
            $before[$kind] = $model::where('company_id', $actor->company_id)->count();
        }
        if ($dryRun) {
            return ['dataset' => self::DATASET, 'company_id' => $actor->company_id, 'dry_run' => true, 'existing' => $before, 'target_dataset' => ['customers' => $small ? 2 : 20, 'products' => $small ? 2 : 24, 'series' => $small ? 1 : 3, 'documents' => $small ? 1 : 12], 'users' => 'Reutilizar owner, issuer y accountant; no cambiar contrasenas.', 'emissions' => $small ? 0 : 6, 'note' => $small ? 'Solo un borrador, sin consumir numeros.' : 'Solo las seis emisiones consumen numeros. Las referencias existentes se conservan.'];
        }
        DB::transaction(function () use ($actor, $small): void {
            Company::whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
            $customers = [];
            for ($i = 1; $i <= ($small ? 2 : 20); $i++) {
                $type = $i <= 10 ? 'ruc' : ($i <= 18 ? 'dni' : 'other');
                $number = $type === 'ruc' ? self::ruc($i) : ($type === 'dni' ? str_pad((string) (99000000 + $i), 8, '0', STR_PAD_LEFT) : 'DEMO-OTRO-'.$i);
                $customers[$i] = $this->record($actor, 'customers', 'C'.sprintf('%02d', $i), Customer::class, fn () => Customer::where('company_id', $actor->company_id)->where('doc_type', $type)->where('doc_number', $number)->first(), function () use ($actor, $i, $type, $number) {
                    $customer = app(SaveCustomer::class)->handle($actor, ['doc_type' => $type, 'doc_number' => $number, 'name' => sprintf('DEMO C%02d - ', $i).($type === 'ruc' ? 'Empresa Sintetica ' : 'Persona Sintetica ').($i % 2 ? 'Peña y Núñez' : 'Diseño y Tecnología').($i % 3 === 0 ? ' - Centro de servicios de demostración y capacitación' : '')." $i", 'address' => 'Direccion ficticia '.$i.($i % 2 ? ' - Av. de Demostracion, bloque B, oficina 205, ciudad de prueba' : ' - Local de prueba'), 'email' => $i % 5 === 0 ? null : sprintf('demo.c%02d@example.test', $i)]);

                    return $i >= 18 ? app(SaveCustomer::class)->handle($actor, ['is_active' => false], $customer) : $customer;
                });
            }
            $products = [];
            for ($i = 1; $i <= ($small ? 2 : 24); $i++) {
                $code = 'DEMO-'.($i <= 12 ? 'P' : 'S').sprintf('%03d', $i <= 12 ? $i : $i - 12);
                $products[$i] = $this->record($actor, 'products', $code, Product::class, fn () => Product::where('company_id', $actor->company_id)->where('code', $code)->first(), function () use ($actor, $i, $code) {
                    $names = [1 => 'Cuaderno de demostración', 2 => 'Archivador de demostración', 13 => 'Servicio de soporte de demostración', 14 => 'Servicio de capacitación de demostración'];
                    $product = app(SaveProduct::class)->handle($actor, ['code' => $code, 'description' => $names[$i] ?? ('DEMO '.($i <= 12 ? 'Producto' : 'Servicio').' '.$i.' - Escenario sintetico de diseno, revision y atencion'.($i % 4 === 0 ? ' con descripcion extensa para explorar el detalle y el ajuste del texto en pantalla y PDF' : '')), 'unit_code' => $i <= 12 ? 'NIU' : 'ZZ', 'currency' => $i >= 21 ? 'USD' : 'PEN', 'tax_type' => ['10', '20', '30'][($i - 1) % 3], 'unit_price_cents' => 750 + $i * 125]);

                    return in_array($i, [19, 20, 24], true) ? app(SaveProduct::class)->handle($actor, ['is_active' => false], $product) : $product;
                });
            }
            $series = [];
            foreach ($small ? ['01'] : ['01', '03'] as $type) {
                $series[$type] = $this->record($actor, 'series', 'active-'.$type, DocumentSeries::class, fn () => DocumentSeries::where('company_id', $actor->company_id)->where('doc_type', $type)->where('is_active', true)->orderBy('code')->first(), function () use ($actor, $type) {
                    for ($n = 1; $n <= 999; $n++) {
                        $code = ($type === '01' ? 'F' : 'B').sprintf('%03d', $n);
                        if (! DocumentSeries::where('company_id', $actor->company_id)->where('doc_type', $type)->where('code', $code)->exists()) {
                            return app(SaveDocumentSeries::class)->handle($actor, ['doc_type' => $type, 'code' => $code]);
                        }
                    }
                    throw new \RuntimeException('No existe codigo de serie disponible.');
                });
            }
            if (! $small) {
                $this->record($actor, 'series', 'inactive-03', DocumentSeries::class, fn () => DocumentSeries::where('company_id', $actor->company_id)->where('doc_type', '03')->where('is_active', false)->first(), function () use ($actor) {
                    for ($n = 999; $n >= 1; $n--) {
                        $code = 'B'.sprintf('%03d', $n);
                        if (! DocumentSeries::where('company_id', $actor->company_id)->where('doc_type', '03')->where('code', $code)->exists()) {
                            $row = app(SaveDocumentSeries::class)->handle($actor, ['doc_type' => '03', 'code' => $code]);

                            return app(SaveDocumentSeries::class)->handle($actor, ['is_active' => false], $row);
                        }
                    }
                    throw new \RuntimeException('No existe codigo de serie disponible.');
                });
            }
            $scenarios = $small ? ['otro-tenant-borrador'] : ['borrador-factura-pen', 'borrador-boleta-pen', 'borrador-factura-usd', 'borrador-fraccionario', 'borrador-afectaciones', 'borrador-muchas-lineas', 'aceptado-factura-pen', 'aceptado-boleta-pen', 'aceptado-factura-usd', 'aceptado-fraccionario', 'rechazado-simulador', 'fallo-temporal-recuperable'];
            foreach ($scenarios as $index => $scenario) {
                $this->record($actor, 'documents', $scenario, Document::class, fn () => null, function () use ($actor, $scenario, $index, $products, $customers, $series) {
                    $usd = str_contains($scenario, 'usd');
                    $type = str_contains($scenario, 'boleta') ? '03' : '01';
                    $indexes = $usd ? [21, 22] : [1, 2];
                    if ($scenario === 'borrador-afectaciones') {
                        $indexes = [1, 2, 3];
                    }
                    if ($scenario === 'borrador-muchas-lineas') {
                        $indexes = range(1, 18);
                    }
                    $items = [];
                    foreach ($indexes as $i) {
                        $items[] = ['product_id' => $products[$i]->id, 'quantity' => str_contains($scenario, 'fraccionario') ? '1.125' : '2.000'];
                    }
                    $doc = app(DraftDocuments::class)->save($actor, ['doc_type' => $type, 'series_id' => $series[$type]->id, 'customer_id' => $type === '03' ? null : $customers[1 + ($index % 2)]->id, 'currency' => $usd ? 'USD' : 'PEN', 'issue_date' => now('America/Lima')->toDateString(), 'items' => $items]);
                    if (in_array($scenario, ['rechazado-simulador', 'fallo-temporal-recuperable'], true)) {
                        DB::table('simulation_scenarios')->insert(['document_id' => $doc->id, 'scenario' => $scenario === 'rechazado-simulador' ? 'rejected' : 'temporary']);
                    }

                    return $doc;
                });
            }
        }, 3);
        if (! $small) {
            foreach (DB::table('local_demo_records')->where(['company_id' => $actor->company_id, 'dataset' => self::DATASET, 'kind' => 'documents'])->get() as $entry) {
                if (str_starts_with($entry->scenario, 'borrador-')) {
                    continue;
                }
                $doc = Document::where('company_id', $actor->company_id)->find($entry->record_id);
                if (! $doc) {
                    continue;
                }
                $metadata = json_decode($entry->metadata, true);
                if ($doc->status === 'draft' && $doc->version === $metadata['initial_version']) {
                    app(RequestIssuance::class)->handle($actor, $doc->id, $doc->version, hash('sha256', self::DATASET.':'.$doc->id));
                }
            }
        }
        $summary = [];
        foreach ($models as $kind => $model) {
            $summary[$kind] = ['created' => $this->counts[$kind]['created'] ?? 0, 'reused' => $kind === 'users' ? $before[$kind] : ($this->counts[$kind]['reused'] ?? 0), 'omitted' => $this->counts[$kind]['omitted'] ?? 0, 'total' => $model::where('company_id', $actor->company_id)->count()];
        }

        return ['dataset' => self::DATASET, 'company_id' => $actor->company_id, 'dry_run' => false, 'summary' => $summary, 'scenarios' => $this->scenarios($actor->company_id)];
    }

    private function record(User $actor, string $kind, string $key, string $model, callable $lookup, callable $create): mixed
    {
        $identity = ['company_id' => $actor->company_id, 'dataset' => self::DATASET, 'kind' => $kind, 'scenario' => $key];
        $entry = DB::table('local_demo_records')->where($identity)->first();
        if ($entry) {
            $record = $model::where('company_id', $actor->company_id)->find($entry->record_id);
            $this->count($kind, $record ? 'reused' : 'omitted');

            return $record;
        }
        $record = $lookup();
        $existed = (bool) $record;
        $record ??= $create();
        DB::table('local_demo_records')->insert($identity + ['record_id' => $record->id, 'metadata' => json_encode(['initial_version' => $kind === 'documents' ? $record->version : null]), 'created_at' => now()]);
        $this->count($kind, $existed ? 'reused' : 'created');

        return $record;
    }

    private function count(string $kind, string $outcome): void
    {
        $this->counts[$kind][$outcome] = ($this->counts[$kind][$outcome] ?? 0) + 1;
    }

    public static function ruc(int $index): string
    {
        $base = '2099'.str_pad((string) $index, 6, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $weight) {
            $sum += (int) $base[$i] * $weight;
        }

        return $base.((11 - $sum % 11) % 10);
    }

    public function scenarios(string $company): array
    {
        return DB::table('local_demo_records as r')->leftJoin('documents as d', function ($join): void {
            $join->on('d.id', '=', 'r.record_id')->on('d.company_id', '=', 'r.company_id');
        })->where(['r.company_id' => $company, 'r.dataset' => self::DATASET, 'r.kind' => 'documents'])->orderBy('r.scenario')->get(['r.scenario', 'r.record_id as id', 'd.status', 'd.series_code', 'd.number', 'd.total_cents', 'd.currency'])->all();
    }
}
