<?php

use App\Actions\Issuance\ProcessEmission;
use App\Actions\Issuance\RequestIssuance;
use App\Actions\Payments\Collections;
use App\Actions\Payments\PaymentWork;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\MerchantAccount;

$c = require __DIR__.'/bootstrap.php';
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
function race(array $inputs): array
{
    $directory = sys_get_temp_dir().'/facturly-payments-'.bin2hex(random_bytes(16));
    mkdir($directory, 0700);
    $workers = [];
    try {
        foreach ($inputs as $i => $input) {
            $p = new Process([PHP_BINARY, __DIR__.'/payments-worker.php', ...$input, $directory, (string) $i], dirname(__DIR__, 2));
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
        foreach ($workers as $i => $p) {
            $exit = $p->wait();
            if ($inputs[$i][0] === 'interrupt-create' && $exit === 73) {
                $results[] = ['pid' => (int) file_get_contents($directory.'/ready-'.$i), 'status' => 'interrupted'];

                continue;
            }
            if ($exit !== 0) {
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

$u = User::factory()->create(['role' => 'owner']);
MerchantAccount::create($u->company_id);
$d = Document::factory()->create(['company_id' => $u->company_id, 'created_by' => $u->id]);
$d->items()->first()->update(['unit_price_cents' => 1000]);
app(RequestIssuance::class)->handle($u, $d->id, 1, (string) Str::uuid());
app(ProcessEmission::class)->handle($u->company_id, $d->id, 2);
$d->refresh();
$key = (string) Str::uuid();
$same = race([['request', $u->id, $d->id, $key], ['request', $u->id, $d->id, $key]]);
verify(DB::table('payment_orders')->count() === 1 && DB::table('payment_requests')->count() === 1, 'Concurrent same-key requests must create one order');
$other = race([['request', $u->id, $d->id, (string) Str::uuid()], ['request', $u->id, $d->id, (string) Str::uuid()]]);
verify(DB::table('payment_orders')->count() === 1, 'Different keys must reuse equivalent pending intent');
$o = DB::table('payment_orders')->first();
$create = DB::table('payment_work')->first();
DB::statement('CREATE TABLE test_remote_preferences (order_id uuid PRIMARY KEY)');
$interrupted = race([['interrupt-create', $u->id, $o->id, $create->id]]);
verify($interrupted[0]['status'] === 'interrupted' && DB::table('payment_work')->value('status') === 'processing'
    && DB::table('payment_orders')->value('preference_id') === null, 'Interrupted worker should leave an unconfirmed preference');
DB::table('payment_work')->where('id', $create->id)->update(['lease_until' => now()->subSecond()]);
config(['payments.queue_connection' => 'database']);
app(PaymentWork::class)->tick($u->company_id);
$jobs = race([['work', $u->id, $o->id, $create->id], ['work', $u->id, $o->id, $create->id]]);
verify(DB::table('payment_orders')->value('status') === 'open', 'Concurrent creation workers must publish a preference');
verify(DB::table('payment_work')->value('attempts') === 2 && DB::table('test_remote_preferences')->count() === 1, 'Restart must recover the original preference exactly once');
Collections::enqueue('payment', $u->company_id, $o->id, '500', 'first');
Collections::enqueue('payment', $u->company_id, $o->id, '500', 'duplicate');
$works = DB::table('payment_work')->where('kind', 'payment')->get();
$payments = race([['work', $u->id, $o->id, $works[0]->id], ['work', $u->id, $o->id, $works[1]->id]]);
verify(DB::table('payments')->count() === 1 && app(Collections::class)->balance($d)['paid_cents'] === $d->total_cents, 'Concurrent notifications must apply payment once');
Collections::enqueue('payment', $u->company_id, $o->id, '501', 'second');
Collections::enqueue('payment', $u->company_id, $o->id, '502', 'third');
$works = DB::table('payment_work')->where('status', 'pending')->get();
$distinct = race([['work', $u->id, $o->id, $works[0]->id], ['work', $u->id, $o->id, $works[1]->id]]);
verify(DB::table('payments')->count() === 3 && app(Collections::class)->balance($d)['excess_cents'] === $d->total_cents * 2, 'Distinct simultaneous payments must both remain visible with excess');
echo json_encode(['result' => 'PASS', 'same_key' => $same, 'different_keys' => $other, 'lost_preference_response' => $interrupted, 'creation_workers' => $jobs, 'duplicate_payment' => $payments, 'distinct_payments' => $distinct, 'external_provider' => 'fake; no external payment'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
