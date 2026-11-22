<?php

namespace App\Console\Commands;

use App\Actions\Customers\SaveCustomer;
use App\Actions\Documents\DraftDocuments;
use App\Actions\Issuance\ProcessEmission;
use App\Actions\Issuance\RequestIssuance;
use App\Actions\Products\SaveProduct;
use App\Actions\Series\SaveDocumentSeries;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrepareCreditDemo extends Command
{
    protected $signature = 'demo:credit-note {case=devolucion : Identificador del ejemplo}';

    protected $description = 'Prepara una factura de S/ 1.180 con un pago histórico sintético de S/ 1.000.';

    private function series(User $actor, string $type, string $code): DocumentSeries
    {
        return DocumentSeries::where(['company_id' => $actor->company_id, 'doc_type' => $type, 'code' => $code])->first()
            ?? app(SaveDocumentSeries::class)->handle($actor, ['doc_type' => $type, 'code' => $code]);
    }

    public function handle(): int
    {
        $case = (string) $this->argument('case');
        $connection = DB::selectOne('SELECT current_database() AS db, current_user AS usr');
        $local = app()->environment('local') && $connection->db === 'facturly' && $connection->usr === 'facturly';
        $e2e = app()->environment('testing') && env('E2E_ISOLATED') === '1' && $connection->db === 'facturly_series_testing_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee' && $connection->usr === 'facturly_test';
        if ((! $local && ! $e2e) || ! preg_match('/^[a-z0-9-]{1,32}$/D', $case)) {
            $this->error('Se requiere la demo local o E2E aislada y un identificador alfanumérico de hasta 32 caracteres.');

            return self::FAILURE;
        }
        $actor = User::where('email', $local ? 'owner@alfa.example.test' : 'foreign@integral.test')->where('role', 'owner')->firstOrFail();
        $doc = DB::transaction(function () use ($actor, $case) {
            Company::whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
            $key = ['company_id' => $actor->company_id, 'dataset' => 'credit-note-demo-v1', 'kind' => 'documents', 'scenario' => $case];
            $existing = DB::table('local_demo_records')->where($key)->value('record_id');
            if ($existing) {
                return Document::where('company_id', $actor->company_id)->findOrFail($existing);
            }
            $series = $this->series($actor, '01', 'FR01');
            $this->series($actor, '07', 'FC01');
            $customer = Customer::where(['company_id' => $actor->company_id, 'doc_type' => 'ruc', 'doc_number' => '20131312955'])->first()
                ?? app(SaveCustomer::class)->handle($actor, ['doc_type' => 'ruc', 'doc_number' => '20131312955', 'name' => 'Cliente de devolución demo']);
            $items = [];
            foreach ([80000, 20000] as $index => $price) {
                $product = app(SaveProduct::class)->handle($actor, ['code' => 'DEV-'.Str::upper(Str::random(10)),
                    'description' => $index === 0 ? 'Equipo de demostración' : 'Accesorio devuelto',
                    'unit_code' => 'NIU', 'currency' => 'PEN', 'tax_type' => '10', 'unit_price_cents' => $price, 'is_active' => true]);
                $items[] = ['product_id' => $product->id, 'quantity' => '1.000'];
            }
            $doc = app(DraftDocuments::class)->save($actor, ['doc_type' => '01', 'customer_id' => $customer->id, 'currency' => 'PEN',
                'series_id' => $series->id, 'issue_date' => now('America/Lima')->toDateString(), 'items' => $items]);
            app(RequestIssuance::class)->handle($actor, $doc->id, 1, 'credit-demo-'.$doc->id);
            DB::table('local_demo_records')->insert($key + ['record_id' => $doc->id, 'created_at' => now()]);

            return $doc->refresh();
        });
        // The simulator runs after commit. A concurrent worker may already own the submission.
        if ($doc->status === 'queued') {
            app(ProcessEmission::class)->handle($actor->company_id, $doc->id, $doc->version);
        }
        if ($doc->refresh()->status !== 'accepted') {
            $this->error('La emisión sigue pendiente. Repite el comando con el mismo identificador.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($actor, $doc) {
            Document::whereKey($doc->id)->lockForUpdate()->firstOrFail();
            $providerId = substr(str_replace('-', '', $doc->id), -25);
            if (DB::table('payments')->where(['provider' => 'demo-history', 'provider_id' => $providerId])->exists()) {
                return;
            }
            $common = ['company_id' => $actor->company_id, 'document_id' => $doc->id, 'amount_cents' => 100000, 'currency' => 'PEN', 'provider' => 'demo-history', 'created_at' => now(), 'updated_at' => now()];
            $order = (string) Str::uuid();
            DB::table('payment_orders')->insert($common + ['id' => $order, 'requested_by' => $actor->id, 'status' => 'closed', 'expires_at' => now()]);
            DB::table('payments')->insert($common + ['id' => (string) Str::uuid(), 'order_id' => $order, 'provider_id' => $providerId,
                'status' => 'approved', 'applied_cents' => 100000, 'confirmed_at' => now(), 'provider_updated_at' => now()]);
        });
        $this->line('/documents/'.$doc->id);

        return self::SUCCESS;
    }
}
