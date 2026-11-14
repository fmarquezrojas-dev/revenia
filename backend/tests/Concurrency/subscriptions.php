<?php

use App\Actions\Payments\Collections;
use App\Actions\Subscriptions\Subscriptions;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

$c = require __DIR__.'/bootstrap.php';
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
config(['subscriptions.test_fixture_grants' => false]);
function race(array $inputs): array
{
    $directory = sys_get_temp_dir().'/facturly-payments-'.bin2hex(random_bytes(16));
    mkdir($directory, 0700);
    $workers = [];
    try {
        foreach ($inputs as $i => $input) {
            $p = new Process([PHP_BINARY, __DIR__.'/subscriptions-worker.php', ...$input, $directory, (string) $i], dirname(__DIR__, 2));
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

$u = User::factory()->create(['role' => 'owner']);
DB::table('plans')->where('code', 'demo-basic')->update(['emission_limit' => 1]);
app(Subscriptions::class)->grantDemo($u->company_id);
$a = Document::factory()->create(['company_id' => $u->company_id, 'created_by' => $u->id]);
$b = Document::factory()->create(['company_id' => $u->company_id, 'created_by' => $u->id, 'series_id' => $a->series_id]);
$last = race([['issue', $u->id, $a->id, (string) Str::uuid()], ['issue', $u->id, $b->id, (string) Str::uuid()]]);
$statuses = array_column($last, 'status');
sort($statuses);
verify($statuses === [202, 422], 'Only one emission can consume last quota');
verify(DB::table('emission_consumptions')->count() === 1 && DB::table('company_usage')->value('consumed') === 1, 'Quota must be consumed once');
verify($a->series->fresh()->current_number === 1, 'Rejected operation cannot consume number');
verify(DB::table('emission_outbox')->count() === 1, 'Only winner creates outbox');
$renew = race([['purchase', $u->id, 'demo-plus', (string) Str::uuid()], ['purchase', $u->id, 'demo-plus', (string) Str::uuid()]]);
verify(array_column($renew, 'status') === [202, 202] && DB::table('payment_orders')->count() === 1, 'Concurrent renewals reuse one intention');
$o = DB::table('payment_orders')->first();
DB::table('payment_orders')->where('id', $o->id)->update(['preference_id' => 'pref-test', 'status' => 'open']);
Collections::enqueue('payment', $u->company_id, $o->id, '500', 'event-a');
Collections::enqueue('payment', $u->company_id, $o->id, '500', 'event-b');
$works = DB::table('payment_work')->where('kind', 'payment')->get();
$paid = race([['payment', $u->id, $o->id, $works[0]->id], ['payment', $u->id, $o->id, $works[1]->id]]);
verify(DB::table('payments')->count() === 1 && DB::table('company_usage')->count() === 2, 'Concurrent payment notifications activate exactly one next period');
echo json_encode(['result' => 'PASS', 'last_quota' => $last, 'renewal' => $renew, 'payment_activation' => $paid, 'provider' => 'fake; no external payment'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
