<?php

use App\Actions\Documents\CreditNotes;
use App\Actions\Issuance\EmissionOutbox;
use App\Actions\Issuance\ProcessEmission;
use App\Actions\Issuance\RequestIssuance;
use App\Actions\Payments\DocumentBalances;
use App\Actions\Treasury\BankReceipts;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\User;
use Illuminate\Queue\Connectors\RedisConnector;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

$c = require __DIR__.'/bootstrap.php';
if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
function race(array $inputs): array
{
    $directory = sys_get_temp_dir().'/facturly-issuance-'.bin2hex(random_bytes(16));
    mkdir($directory, 0700);
    $workers = [];
    try {
        foreach ($inputs as $i => $input) {
            $p = new Process([PHP_BINARY, __DIR__.'/issuance-worker.php', ...$input, $directory, (string) $i], dirname(__DIR__, 2));
            $p->setTimeout(150);
            $p->start();
            $workers[] = $p;
        }
        $deadline = microtime(true) + 120;
        while (count(glob($directory.'/ready-*')) !== count($inputs)) {
            foreach ($workers as $p) {
                if (! $p->isRunning()) {
                    throw new RuntimeException($p->getErrorOutput());
                }
            }if (microtime(true) > $deadline) {
                throw new RuntimeException('Barrier timeout');
            }usleep(10000);
        }
        touch($directory.'/go');
        $results = [];
        foreach ($workers as $p) {
            if ($p->wait() !== 0) {
                throw new RuntimeException($p->getErrorOutput());
            }$results[] = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        if (count(array_unique(array_column($results, 'pid'))) !== count($inputs)) {
            throw new RuntimeException('Expected independent PostgreSQL connections');
        }

        return $results;
    } finally {
        foreach ($workers as $p) {
            if ($p->isRunning()) {
                $p->stop(1);
            }
        }foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }rmdir($directory);
    }
}
function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
$actor = User::factory()->create(['role' => 'issuer']);
$doc = Document::factory()->create(['company_id' => $actor->company_id, 'created_by' => $actor->id]);
$key = (string) Str::uuid();
$same = race([['issue', $actor->id, $doc->id, $key], ['issue', $actor->id, $doc->id, $key]]);
verify(array_column($same, 'status') === [202, 202] && $doc->fresh()->number === 1 && DB::table('emission_requests')->count() === 1, 'Concurrent same key must replay one issuance');
$second = Document::factory()->create(['company_id' => $actor->company_id, 'created_by' => $actor->id, 'series_id' => $doc->series_id]);
$different = race([['issue', $actor->id, $second->id, (string) Str::uuid()], ['issue', $actor->id, $second->id, (string) Str::uuid()]]);
$statuses = array_column($different, 'status');
sort($statuses);
verify($statuses === [202, 409], 'Different keys must have one winner');
$third = Document::factory()->create(['company_id' => $actor->company_id, 'created_by' => $actor->id, 'series_id' => $doc->series_id]);
$fourth = Document::factory()->create(['company_id' => $actor->company_id, 'created_by' => $actor->id, 'series_id' => $doc->series_id]);
$series = race([['issue', $actor->id, $third->id, (string) Str::uuid()], ['issue', $actor->id, $fourth->id, (string) Str::uuid()]]);
verify(array_column($series, 'status') === [202, 202] && $doc->series->fresh()->current_number === 4 && Document::whereNotNull('number')->distinct()->count('number') === 4, 'Same series must allocate distinct numbers');
$jobs = race([['job', $actor->id, $doc->id, 'unused'], ['job', $actor->id, $doc->id, 'unused']]);
verify($doc->fresh()->status === 'accepted' && DB::table('emission_attempts')->where('document_id', $doc->id)->count() === 1 && DB::table('simulated_submissions')->count() === 1, 'Duplicate jobs must claim one attempt');
$publishers = race([['publish', $actor->id, $doc->id, 'unused'], ['publish', $actor->id, $doc->id, 'unused']]);
verify(DB::table('jobs')->count() === 5 && DB::table('emission_outbox')->whereNotNull('published_at')->count() === 5, 'Concurrent publishers must claim four emissions and one accepted PDF once');

$pdfWorkers = race([['pdf', $actor->id, $doc->id, 'unused'], ['pdf', $actor->id, $doc->id, 'unused']]);
verify(DB::table('document_pdfs')->where('document_id', $doc->id)->value('status') === 'ready' && DB::table('document_pdf_files')->where('document_id', $doc->id)->count() === 1 && DB::table('document_delivery_attempts')->where('document_id', $doc->id)->where('kind', 'pdf')->count() === 1, 'Duplicate PDF workers must publish once outside SQL transaction');

// Real Redis delivery uses a unique prefix; the development queue is never read or flushed.
$redisConfig = config('database.redis');
$redisConfig['options']['prefix'] = 'facturly-issuance-test-'.bin2hex(random_bytes(16)).':';
$redisConfig['unavailable'] = $redisConfig['default'];
$redisConfig['unavailable']['host'] = '127.0.0.1';
$redisConfig['unavailable']['port'] = 1;
$redisConfig['unavailable']['max_retries'] = 0;
$redisConfig['unavailable']['timeout'] = 0.2;
$manager = new RedisManager(app(), 'phpredis', $redisConfig);
Queue::addConnector('redis', fn () => new RedisConnector($manager));
config(['queue.connections.issuance_unavailable' => ['driver' => 'redis', 'connection' => 'unavailable', 'queue' => 'emissions', 'retry_after' => 120],
    'queue.connections.issuance_live' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'emissions', 'retry_after' => 120],
    'issuance.queue_connection' => 'issuance_unavailable']);
$redisDoc = Document::factory()->create(['company_id' => $actor->company_id, 'created_by' => $actor->id]);
app(RequestIssuance::class)->handle($actor, $redisDoc->id, 1, (string) Str::uuid());
verify(DB::transactionLevel() === 0 && $redisDoc->fresh()->status === 'queued', 'Issuance must commit before Redis publication');
verify(app(EmissionOutbox::class)->publish(1) === 0, 'Unavailable Redis must leave pending outbox');
verify(DB::table('emission_outbox')->where('document_id', $redisDoc->id)->whereNull('published_at')->value('last_error') === 'queue_unavailable', 'Pending record must survive actual connection failure');
config(['issuance.queue_connection' => 'issuance_live']);
DB::table('emission_outbox')->where('document_id', $redisDoc->id)->update(['available_at' => now()]);
verify(app(EmissionOutbox::class)->publish(1) === 1, 'Redis recovery must publish committed outbox');
Artisan::call('queue:work', ['connection' => 'issuance_live', '--queue' => 'emissions', '--once' => true, '--tries' => 1, '--timeout' => 45]);
verify($redisDoc->fresh()->status === 'accepted' && $redisDoc->fresh()->attempt_count === 1 && $redisDoc->fresh()->number === 1, 'Real Redis job must finish once with its original number');
verify(Queue::connection('issuance_live')->size('emissions') === 0, 'Isolated Redis queue must be empty');
$manager->purge('default');
$creditSeries = DocumentSeries::factory()->for($actor->company)->create(['doc_type' => '07', 'code' => 'BC01']);
// The older concurrency fixtures are zero-valued. Create one creditable historical line.
$original = Document::factory()->create(['company_id' => $actor->company_id, 'created_by' => $actor->id]);
$original->items()->first()->update(['unit_price_cents' => 2000]);
app(RequestIssuance::class)->handle($actor, $original->id, 1, (string) Str::uuid());
app(ProcessEmission::class)->handle($actor->company_id, $original->id, 2);
$selection = ['item_ids' => $original->items->pluck('id')->all(), 'series_id' => $creditSeries->id, 'reason' => 'Devolución simultánea'];
$inputs = [];
for ($i = 0; $i < 2; $i++) {
    $note = app(CreditNotes::class)->draft($actor, $original->id, $selection);
    $inputs[] = ['issue', $actor->id, $note->id, (string) Str::uuid()];
}
$creditRace = race($inputs);
$statuses = array_column($creditRace, 'status');
sort($statuses);
verify($statuses === [202, 409] && $creditSeries->fresh()->current_number === 1 && DB::table('credit_note_lines')->where('state', 'reserved')->count() === 1
    && DB::table('emission_consumptions')->whereIn('document_id', array_column($inputs, 2))->count() === 1, 'Overlapping credits must reserve one line and consume one number and quota');
$owner = User::factory()->create(['role' => 'owner']);
$customer = Customer::factory()->for($owner->company)->create();
$sales = [];
foreach ([1, 2] as $number) {
    $sale = Document::factory()->create(['company_id' => $owner->company_id, 'created_by' => $owner->id,
        'customer_id' => $customer->id, 'exempt_cents' => 5000, 'total_cents' => 5000]);
    $sale->update(['status' => 'accepted', 'series_code' => $sale->series->code, 'number' => $number,
        'issued_at' => now(), 'submission_id' => (string) Str::uuid(), 'fiscal_snapshot' => ['customer' => ['id' => $customer->id]]]);
    $sales[] = $sale;
}
BankReceipts::write($owner, 'manage-treasury', fn ($user) => BankReceipts::account($user, null, [
    'alias' => 'Prueba', 'institution' => 'Prueba', 'currency' => 'PEN', 'active' => true,
]));
$account = DB::table('bank_accounts')->where('company_id', $owner->company_id)->value('id');
$bank = fn ($action, $id, $fields) => BankReceipts::write($owner, 'manage-treasury',
    fn ($user) => app(BankReceipts::class)->execute($user, $action, $id, $fields + ['request_id' => (string) Str::uuid()]));
$receipt = $bank('register', null, ['account_id' => $account, 'customer_id' => $customer->id, 'bank_date' => now('America/Lima')->toDateString(),
    'currency' => 'PEN', 'origin' => 'customer', 'reference' => 'RC11', 'method' => 'deposit', 'amount_cents' => 5000])['id'];
$bank('confirm', $receipt, ['revision' => 1, 'verified' => true, 'origin' => 'customer']);
$bankRace = race(array_map(fn ($sale) => ['bank', $owner->id, $sale->id, $receipt], $sales));
$statuses = array_column($bankRace, 'status');
sort($statuses);
verify($statuses === [200, 409], 'A concurrent distribution of the same receipt must have one winner');
verify(DB::table('bank_applications')->where('receipt_id', $receipt)->count() === 1
    && (int) DB::table('bank_receipts')->where('id', $receipt)->value('applied_cents') === 5000
    && (int) DocumentBalances::portfolio($owner->company_id, 'PEN')->sum('outstanding_cents') === 5000,
    'One deposit must reduce the portfolio exactly once');
echo json_encode(['result' => 'PASS', 'same_key' => $same, 'different_keys' => $different, 'same_series' => $series, 'duplicate_jobs' => $jobs, 'publishers' => $publishers, 'numbers' => [1, 2, 3, 4], 'redis_unavailable_then_recovered' => 'PASS', 'duplicate_pdf_workers' => $pdfWorkers, 'overlapping_credits' => $creditRace, 'same_bank_receipt' => $bankRace], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
