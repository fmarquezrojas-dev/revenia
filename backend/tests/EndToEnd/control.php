<?php

use App\Actions\Payments\Collections;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/bootstrap.php';
$operation = $argv[1] ?? '';
if ($operation === 'approve') {
    $order = DB::table('payment_orders')->find($argv[2] ?? '');
    if (! $order || ! $order->preference_id) {
        throw new RuntimeException('Persisted checkout required');
    }
    $id = $argv[3] ?? '500';
    $payload = ['id' => $id, 'collector_id' => '123', 'external_reference' => $order->id, 'preference_id' => $order->preference_id, 'live_mode' => false, 'currency_id' => 'PEN', 'transaction_amount' => sprintf('%d.%02d', intdiv($order->amount_cents, 100), $order->amount_cents % 100), 'transaction_amount_refunded' => '0', 'status' => 'approved', 'date_last_updated' => now()->toIso8601String()];
    DB::table('e2e_provider_payments')->updateOrInsert(['provider_id' => $id], ['order_id' => $order->id, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    for ($i = 0; $i < 10; $i++) {
        Collections::enqueue('payment', $order->company_id, $order->id, $id, (string) Str::uuid());
    }
    Artisan::call('payments:reconcile', ['--tick' => true, '--limit' => 50]);
    echo 'TEN_SYNTHETIC_NOTIFICATIONS_QUEUED'.PHP_EOL;
} elseif ($operation === 'inspect') {
    echo json_encode(['documents' => DB::table('documents')->count(), 'payments' => DB::table('payments')->count(), 'consumptions' => DB::table('emission_consumptions')->count(), 'outbox_pending' => DB::table('emission_outbox')->whereNull('published_at')->count(), 'failed_jobs' => DB::table('failed_jobs')->count()]).PHP_EOL;
} elseif ($operation === 'expire-sessions') {
    DB::table('sessions')->delete();
    echo 'ISOLATED_SESSIONS_EXPIRED'.PHP_EOL;
} else {
    throw new RuntimeException('Unsupported isolated operation');
}
