<?php

use App\Actions\Issuance\RequestIssuance;
use App\Actions\Payments\PaymentWork;
use App\Actions\Subscriptions\Subscriptions;
use App\Contracts\PaymentGateway;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

$c = require __DIR__.'/bootstrap.php';
config(['payments.enabled' => true, 'payments.access_token' => 'synthetic-test-token-not-real', 'payments.collector_id' => '123', 'payments.webhook_secret' => 'synthetic-webhook-secret', 'payments.notification_url' => 'https://demo.example.test/webhook', 'payments.return_origin' => 'https://demo.example.test']);
app()->instance(PaymentGateway::class, new class implements PaymentGateway
{
    public function create(object $o): array
    {
        throw new RuntimeException('Unexpected external creation');
    }

    public function recover(object $o): ?array
    {
        return null;
    }

    public function paymentIds(object $o): array
    {
        return [];
    }

    public function payment(string $id): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('HTTP inside transaction');
        }$o = DB::table('payment_orders')->first();

        return ['id' => $id, 'external_reference' => $o->id, 'collector_id' => '123', 'preference_id' => 'pref-test', 'live_mode' => false, 'currency_id' => 'PEN', 'transaction_amount' => '49.00', 'status' => 'approved', 'date_last_updated' => now()->toIso8601String()];
    }
});
[$script,$action,$actor,$target,$key,$directory,$index] = $argv;
$pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
touch($directory.'/ready-'.$index);
$deadline = microtime(true) + 120;
while (! file_exists($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout');
    }usleep(10000);
}
try {
    if ($action === 'issue') {
        app(RequestIssuance::class)->handle(User::findOrFail($actor), $target, 1, $key);
    } elseif ($action === 'purchase') {
        app(Subscriptions::class)->purchase(User::findOrFail($actor), $target, $key);
    } else {
        app(PaymentWork::class)->run($key);
    }$status = 202;
} catch (HttpResponseException $e) {
    $status = $e->getResponse()->getStatusCode();
}
echo json_encode(['pid' => $pid, 'status' => $status], JSON_THROW_ON_ERROR);
