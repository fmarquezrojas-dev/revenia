<?php

use App\Actions\Payments\Collections;
use App\Actions\Payments\PaymentWork;
use App\Contracts\PaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$c = require __DIR__.'/bootstrap.php';
config(['payments.enabled' => true, 'payments.access_token' => 'synthetic-token-not-real', 'payments.collector_id' => '123', 'payments.webhook_secret' => 'synthetic-webhook-secret', 'payments.notification_url' => 'https://demo.example.test/webhook', 'payments.return_origin' => 'https://demo.example.test']);
app()->instance(PaymentGateway::class, new class implements PaymentGateway
{
    private function outside(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('External request under SQL transaction');
        }
    }

    public function create(object $o): array
    {
        $this->outside();
        usleep(150000);

        return ['id' => '123-test', 'url' => 'https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=123-test'];
    }

    public function recover(object $o): ?array
    {
        $this->outside();

        return null;
    }

    public function paymentIds(object $o): array
    {
        $this->outside();

        return [];
    }

    public function payment(string $id): array
    {
        $this->outside();
        $o = DB::table('payment_orders')->first();

        return ['id' => $id, 'collector_id' => '123', 'external_reference' => $o->id, 'preference_id' => '123-test', 'live_mode' => false, 'currency_id' => 'PEN', 'transaction_amount' => '11.80', 'transaction_amount_refunded' => '0', 'status' => 'approved', 'date_last_updated' => '2026-11-13T10:00:00Z'];
    }
});
[$script,$action,$actor,$document,$key,$directory,$index] = $argv;
$pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
touch($directory.'/ready-'.$index);
$deadline = microtime(true) + 120;
while (! file_exists($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout');
    }usleep(10000);
}
if ($action === 'request') {
    app(Collections::class)->request(User::findOrFail($actor), $document, $key, 'create');
} else {
    app(PaymentWork::class)->run($key);
}
echo json_encode(['pid' => $pid, 'status' => 'completed'], JSON_THROW_ON_ERROR);
