<?php

use App\Actions\Documents\DraftDocuments;
use App\Actions\Issuance\RequestIssuance;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/bootstrap.php';
$manifest = storage_path('e2e-outage.json');
if (in_array($argv[1] ?? '', ['prepare', 'prepare-interrupt'], true)) {
    $company = Company::factory()->create();
    $actor = User::factory()->for($company)->owner()->create();
    $customer = Customer::factory()->for($company)->create(['doc_type' => 'ruc', 'doc_number' => '20131312955']);
    $series = DocumentSeries::factory()->for($company)->create(['doc_type' => '01', 'code' => 'F001']);
    $product = Product::factory()->for($company)->create(['currency' => 'PEN', 'unit_price_cents' => 1000, 'tax_type' => '10']);
    $doc = app(DraftDocuments::class)->save($actor, ['customer_id' => $customer->id, 'series_id' => $series->id, 'doc_type' => '01', 'currency' => 'PEN', 'issue_date' => now('America/Lima')->toDateString(), 'items' => [['product_id' => $product->id, 'quantity' => '1.000']]]);
    app(RequestIssuance::class)->handle($actor, $doc->id, 1, (string) Str::uuid());
    if ($argv[1] === 'prepare-interrupt') {
        file_put_contents(storage_path('e2e-interrupt-submission'), $doc->fresh()->submission_id);
    }
    file_put_contents($manifest, json_encode(['id' => $doc->id], JSON_THROW_ON_ERROR));
}
$id = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR)['id'];
$doc = Document::findOrFail($id);
$outbox = DB::table('emission_outbox')->where('document_id', $id)->where('kind', 'emission')->first();
$consumptions = DB::table('emission_consumptions')->where('document_id', $id)->count();
if ($doc->number !== 1 || $consumptions !== 1) {
    throw new RuntimeException('Number or quota duplicated during outage');
}
echo json_encode(['id' => $id, 'status' => $doc->status, 'number' => $doc->number, 'consumptions' => $consumptions, 'publication_error' => $outbox->last_error, 'published' => $outbox->published_at !== null, 'attempts' => $doc->attempt_count, 'provider_calls' => DB::table('simulated_submissions')->where('id', $doc->submission_id)->value('calls'), 'paused' => is_file(storage_path('e2e-worker-paused'))], JSON_THROW_ON_ERROR).PHP_EOL;
