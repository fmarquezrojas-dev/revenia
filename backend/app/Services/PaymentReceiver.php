<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final readonly class PaymentReceiver
{
    private function __construct(
        public string $kind, public string $collector, public ?string $connection, public ?string $company,
        #[\SensitiveParameter] private string $token, #[\SensitiveParameter] private string $secret,
        private ?string $credentialRevision = null,
    ) {}

    public static function platformReady(): bool
    {
        return MercadoPagoGateway::configured() && ! DB::table('merchant_connections')
            ->where('collector_id', (string) config('payments.collector_id'))->exists();
    }

    public static function forWebhook(?string $connection): self
    {
        if (! MercadoPagoGateway::transportReady()) {
            throw new \DomainException('payments_not_configured');
        }
        if ($connection === null) {
            $collector = (string) config('payments.collector_id');
            if (! self::platformReady()) {
                throw new \DomainException('platform_receiver_unavailable');
            }

            return new self('platform', $collector, null, null, config('payments.access_token'), config('payments.webhook_secret'));
        }
        $row = DB::table('merchant_connections')->find($connection);
        if (! $row || $row->collector_id === (string) config('payments.collector_id')) {
            throw new \DomainException('merchant_receiver_unavailable');
        }

        return new self('merchant', $row->collector_id, $row->id, $row->company_id,
            Crypt::decryptString($row->access_token), Crypt::decryptString($row->webhook_secret), $row->access_token);
    }

    public static function forOrder(object $order): self
    {
        if ($order->receiver_kind === 'legacy') {
            throw new \DomainException('receiver_unknown');
        }
        $receiver = self::forWebhook($order->connection_id);
        $receiver->assertOrder($order);

        return $receiver;
    }

    public function assertOrder(object $order): void
    {
        if ($order->receiver_kind !== $this->kind || $order->environment !== 'sandbox' || $order->collector_id !== $this->collector
            || $order->connection_id !== $this->connection || ($this->company !== null && $order->company_id !== $this->company)
            || ($this->kind === 'platform' ? ! $order->subscription_id : ! $order->document_id)) {
            throw new \DomainException('receiver_mismatch');
        }
    }

    public function invalidateCredentials(): void
    {
        if ($this->connection === null) {
            return;
        }
        DB::transaction(function (): void {
            DB::table('companies')->where('id', $this->company)->lockForUpdate()->firstOrFail();
            $changed = DB::table('merchant_connections')->where('company_id', $this->company)
                ->where('id', $this->connection)->where('active', true)->where('access_token', $this->credentialRevision)
                ->update(['active' => false, 'verification_error' => 'test_receiver_unverified', 'updated_at' => now()]);
            if ($changed) {
                DB::table('payment_orders')->where('company_id', $this->company)->where('connection_id', $this->connection)
                    ->whereIn('status', ['pending', 'open'])->update(['status' => 'stale', 'updated_at' => now()]);
            }
        });
    }

    public function recordVerification(): void
    {
        if ($this->connection !== null) {
            DB::table('merchant_connections')->where('company_id', $this->company)
                ->where('id', $this->connection)->where('active', true)->where('access_token', $this->credentialRevision)
                ->update(['verified_at' => now()]);
        }
    }

    public function token(): string
    {
        return $this->token;
    }

    public function signingSecret(): string
    {
        return $this->secret;
    }
}
