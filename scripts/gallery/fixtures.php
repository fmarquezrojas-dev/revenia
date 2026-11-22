<?php

use App\Actions\Delivery\GenerateDocumentPdf;
use App\Actions\Documents\CreditNotes;
use App\Actions\Issuance\ProcessEmission;
use App\Actions\Issuance\RequestIssuance;
use App\Actions\Payments\CollectionFollowup;
use App\Actions\Treasury\BankReceipts;
use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne('SELECT current_database() AS db, current_user AS role, system_identifier::text AS cluster FROM pg_control_system()');
if (! app()->environment('local') || ! config('issuance.demo_controls') || config('odoo.enabled') || config('payments.enabled')
    || $identity->db !== 'facturly' || $identity->role !== 'facturly' || $identity->cluster !== getenv('REVENIA_GALLERY_CLUSTER')) {
    throw new RuntimeException('La galería requiere el clúster de demostración efímero del runner.');
}
$owner = User::where('email', 'owner@alfa.example.test')->firstOrFail();
$get = fn (string $case) => Document::where('company_id', $owner->company_id)->findOrFail(DB::table('local_demo_records')
    ->where(['company_id' => $owner->company_id, 'kind' => 'documents', 'scenario' => $case])->value('record_id'));
$invoice = $get('galeria-venta');
$second = $get('galeria-ajuste');
$today = now('America/Lima')->toDateString();
$follow = app(CollectionFollowup::class);
$follow->record($owner, $invoice->id, 'assign', ['revision' => 0, 'assignee_id' => $owner->id]);
$follow->record($owner, $invoice->id, 'contact', ['revision' => 1, 'channel' => 'phone', 'result' => 'contacted',
    'note' => 'Ejemplo de contacto en la empresa de demostración.', 'next_action_on' => $today]);
$follow->record($owner, $invoice->id, 'promise', ['revision' => 2, 'currency' => 'PEN', 'amount_cents' => 12000,
    'due_on' => now('America/Lima')->addWeek()->toDateString()]);
BankReceipts::write($owner, 'manage-treasury', fn ($u) => BankReceipts::account($u, null, [
    'alias' => 'Cobranza en soles', 'institution' => 'Entidad de demostración', 'currency' => 'PEN', 'active' => true,
]));
$account = DB::table('bank_accounts')->where('company_id', $owner->company_id)->value('id');
$bank = fn ($action, $id, $fields) => BankReceipts::write($owner, 'manage-treasury',
    fn ($u) => app(BankReceipts::class)->execute($u, $action, $id, $fields + ['request_id' => (string) Str::uuid()]));
$receipt = $bank('register', null, ['account_id' => $account, 'customer_id' => $invoice->customer_id, 'bank_date' => $today,
    'currency' => 'PEN', 'origin' => 'customer', 'reference' => 'MUESTRA-001', 'method' => 'deposit', 'amount_cents' => 15000])['id'];
$bank('confirm', $receipt, ['revision' => 1, 'verified' => true, 'origin' => 'customer']);
$bank('apply', $receipt, ['revision' => 2, 'lines' => [['document_id' => $invoice->id, 'amount_cents' => 10000], ['document_id' => $second->id, 'amount_cents' => 3000]]]);
$allocation = DB::table('bank_applications')->where('receipt_id', $receipt)->where('document_id', $second->id)->value('id');
$bank('reverse', $receipt, ['revision' => 3, 'application_id' => $allocation, 'reason' => 'Rectificación de la distribución de muestra.']);
$series = DocumentSeries::where('company_id', $owner->company_id)->where('code', 'FC01')->firstOrFail();
$note = app(CreditNotes::class)->draft($owner, $second->id, ['item_ids' => [$second->items()->orderBy('line_number')->get()->last()->id], 'series_id' => $series->id, 'reason' => 'Devolución del accesorio de muestra']);
app(RequestIssuance::class)->handle($owner, $note->id, $note->version, (string) Str::uuid());
$note->refresh();
if ($note->status === 'queued') {
    app(ProcessEmission::class)->handle($owner->company_id, $note->id, $note->version);
}
$pdf = DB::table('document_pdfs')->where('company_id', $owner->company_id)->where('document_id', $invoice->id)->firstOrFail();
app(GenerateDocumentPdf::class)->handle($owner->company_id, $invoice->id, $pdf->version);
echo json_encode(['invoice' => $invoice->id, 'customer' => $invoice->customer_id, 'note' => $note->id,
    'receipt' => $receipt, 'reject' => $get('rechazado-simulador')->id], JSON_THROW_ON_ERROR);
