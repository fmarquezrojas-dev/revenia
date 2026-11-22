<?php

namespace Tests\EndToEnd;

use App\Contracts\PaymentGateway;
use App\Contracts\TaxProvider;
use App\Services\PaymentReceiver;
use App\Services\SimulatedTaxProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class FixturePaymentProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->environment('testing') || getenv('E2E_ISOLATED') !== '1') {
            throw new \RuntimeException('Fixture provider only permitted in isolated E2E image');
        }
        $this->app->bind(TaxProvider::class, fn () => new class implements TaxProvider
        {
            public function lookup(string $submissionId): ?array
            {
                return (new SimulatedTaxProvider)->lookup($submissionId);
            }

            public function submit(string $submissionId, array $payload): array
            {
                $result = (new SimulatedTaxProvider)->submit($submissionId, $payload);
                $marker = storage_path('e2e-interrupt-submission');
                if (is_file($marker) && trim(file_get_contents($marker)) === $submissionId) {
                    file_put_contents(storage_path('e2e-worker-paused'), $submissionId);
                    sleep(120);
                }

                return $result;
            }
        });
        $this->app->bind(PaymentGateway::class, fn () => new class implements PaymentGateway
        {
            public function forReceiver(PaymentReceiver $receiver): PaymentGateway
            {
                return $this;
            }

            public function create(object $order): array
            {
                return ['id' => '123-'.$order->id, 'url' => 'https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=123-'.$order->id];
            }

            public function recover(object $order): ?array
            {
                return $this->create($order);
            }

            public function payment(string $id): array
            {
                $payload = DB::table('e2e_provider_payments')->where('provider_id', $id)->value('payload');
                if (! $payload) {
                    throw new \RuntimeException('No synthetic provider response');
                }

                return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            }

            public function paymentIds(object $order): array
            {
                return DB::table('e2e_provider_payments')->where('order_id', $order->id)->pluck('provider_id')->all();
            }
        });
    }
}
