<?php

use App\Actions\Delivery\GenerateDocumentPdf;
use App\Actions\Documents\DraftDocuments;
use App\Actions\Issuance\ProcessEmission;
use App\Actions\Issuance\RequestIssuance;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentSeries;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/bootstrap.php';
DB::table('plans')->where('code', 'demo-basic')->update(['emission_limit' => 50]);
if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
$company = Company::factory()->create(['legal_name' => 'Compañía Sintética Peña y Núñez', 'address' => 'Av. Demostración 123, Lima']);
$actor = User::factory()->for($company)->create(['name' => 'Ensayo propietario', 'email' => 'owner@delivery.test', 'role' => 'owner', 'password' => 'Delivery-Test-Only-123']);
User::factory()->for($company)->create(['name' => 'Ensayo contador', 'email' => 'accountant@delivery.test', 'role' => 'accountant', 'password' => 'Delivery-Test-Only-123']);
User::factory()->create(['email' => 'foreign@delivery.test', 'role' => 'owner', 'password' => 'Delivery-Test-Only-123']);
$recipient = 'pdf-'.Str::uuid().'@example.test';
$customer = Customer::factory()->for($company)->create(['doc_type' => 'ruc', 'doc_number' => '20131312955', 'name' => 'Diseño y tecnología - Niñez', 'address' => 'Calle Ejemplo 45', 'email' => $recipient]);
$invoice = DocumentSeries::factory()->for($company)->create(['code' => 'F001', 'doc_type' => '01']);
$receipt = DocumentSeries::factory()->for($company)->create(['code' => 'B001', 'doc_type' => '03']);
$samples = [];
foreach ([['factura-pen', '01', 'PEN', 2], ['boleta-anonima', '03', 'PEN', 1], ['factura-usd-50-lineas', '01', 'USD', 50]] as [$name,$type,$currency,$count]) {
    $items = [];
    for ($i = 0; $i < $count; $i++) {
        $description = $count === 50 ? str_repeat('Descripción extensa de servicio, instalación y revisión. ', 4).'Linea '.($i + 1) : 'Servicio de diseño y revisión '.($i + 1);
        $product = Product::factory()->for($company)->create(['description' => $description, 'currency' => $currency, 'unit_price_cents' => 12345, 'tax_type' => ['10', '20', '30'][$i % 3], 'unit_code' => 'ZZ']);
        $items[] = ['product_id' => $product->id, 'quantity' => '1.125'];
    }
    $doc = app(DraftDocuments::class)->save($actor, ['customer_id' => $type === '01' ? $customer->id : null, 'series_id' => $type === '01' ? $invoice->id : $receipt->id, 'doc_type' => $type, 'currency' => $currency, 'issue_date' => now('America/Lima')->toDateString(), 'items' => $items]);
    app(RequestIssuance::class)->handle($actor, $doc->id, 1, (string) Str::uuid());
    app(ProcessEmission::class)->handle($company->id, $doc->id, 2);
    app(GenerateDocumentPdf::class)->handle($company->id, $doc->id, 1);
    $pdf = DB::table('document_pdfs')->where('document_id', $doc->id)->first();
    if ($pdf->status !== 'ready') {
        throw new RuntimeException('Real PDF sample generation failed: '.$pdf->error_code);
    }
    $file = DB::table('document_pdf_files')->where('id', $pdf->current_file_id)->first();
    $directory = storage_path('e2e-pdf-samples');
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }copy(Storage::disk('documents')->path($file->path), $directory.'/'.$name.'.pdf');
    $samples[$name] = $doc->id;
}
file_put_contents(storage_path('e2e-pdf-samples/manifest.json'), json_encode(['documents' => $samples, 'recipient' => $recipient], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'DELIVERY_FIXTURES_READY'.PHP_EOL;
