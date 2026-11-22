<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Money\ProviderAmount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class MercadoPagoGateway implements PaymentGateway
{
    private ?PaymentReceiver $receiver = null;

    public function forReceiver(PaymentReceiver $receiver): PaymentGateway
    {
        $scoped = clone $this;
        $scoped->receiver = $receiver;

        return $scoped;
    }

    public static function transportReady(): bool
    {
        return (bool) config('payments.enabled') && self::publicHttps((string) config('payments.notification_url'))
            && self::publicHttps((string) config('payments.return_origin'));
    }

    public static function notificationUrl(?string $company): string
    {
        return rtrim((string) config('payments.notification_url'), '/').($company ? '/companies/'.$company : '');
    }

    public static function configured(): bool
    {
        return self::transportReady() && is_string(config('payments.access_token')) && strlen(config('payments.access_token')) > 15 && preg_match('/\A[0-9]{1,25}\z/', (string) config('payments.collector_id')) && strlen((string) config('payments.webhook_secret')) >= 16;
    }

    private static function publicHttps(string $url): bool
    {
        $p = parse_url($url);

        return is_array($p) && ($p['scheme'] ?? null) === 'https' && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['fragment']) && ! isset($p['query']) && ! isset($p['port']) && str_contains($p['host'] ?? '', '.') && ! filter_var($p['host'], FILTER_VALIDATE_IP) && ! str_ends_with($p['host'], '.local') && $p['host'] !== 'localhost';
    }

    private function client(): PendingRequest
    {
        if (! self::transportReady() || ! $this->receiver) {
            throw new \DomainException('payments_not_configured');
        }

        return Http::baseUrl('https://api.mercadopago.com')->withToken($this->receiver->token())->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();
    }

    private function get(string $path, array $query = []): array
    {
        $r = $this->client()->get($path, $query);
        if (in_array($r->status(), [401, 403], true)) {
            throw new \DomainException('provider_access_denied');
        }
        if (! $r->successful()) {
            throw new \RuntimeException('provider_unavailable');
        }

        return ProviderAmount::decode($r->body());
    }

    public static function identify(#[\SensitiveParameter] string $token): array
    {
        try {
            $r = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(10)
                ->withoutRedirecting()->get('https://api.mercadolibre.com/users/me');
        } catch (ConnectionException) {
            throw new \RuntimeException('provider_unavailable');
        }
        if ($r->serverError() || $r->status() === 429) {
            throw new \RuntimeException('provider_unavailable');
        }
        $user = $r->successful() ? json_decode($r->body(), true, 64, JSON_BIGINT_AS_STRING) : [];
        $id = (string) ($user['id'] ?? '');
        if (! preg_match('/\A[0-9]{1,25}\z/', $id) || ($user['site_id'] ?? null) !== 'MPE' || ! is_array($user['tags'] ?? null) || ! in_array('test_user', $user['tags'], true)) {
            throw new \DomainException('test_receiver_unverified');
        }

        return ['collector_id' => $id, 'nickname' => mb_substr((string) ($user['nickname'] ?? ''), 0, 160)];
    }

    private function verifyOrder(object $order): void
    {
        if (! $this->receiver) {
            throw new \DomainException('receiver_unknown');
        }
        $this->receiver->assertOrder($order);
        try {
            if (self::identify($this->receiver->token())['collector_id'] !== $order->collector_id) {
                throw new \DomainException('test_receiver_unverified');
            }
        } catch (\DomainException $e) {
            $this->receiver->invalidateCredentials();
            throw $e;
        }
        $this->receiver->recordVerification();
    }

    public static function checkoutUrl(string $url, string $preference): bool
    {
        $p = parse_url($url);
        if (! is_array($p) || ($p['scheme'] ?? '') !== 'https' || ($p['host'] ?? '') !== 'www.mercadopago.com.pe' || ($p['path'] ?? '') !== '/checkout/v1/redirect' || isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) {
            return false;
        }
        if (count(explode('&', $p['query'] ?? '')) !== 1) {
            return false;
        }
        parse_str($p['query'] ?? '', $q);

        return ($q['pref_id'] ?? null) === $preference && count($q) === 1;
    }

    private function preference(array $p, object $order): array
    {
        $id = $p['id'] ?? '';
        $items = $p['items'] ?? [];
        if (! is_string($id) || ! preg_match('/\A[a-zA-Z0-9-]{1,150}\z/', $id) || ($p['external_reference'] ?? null) !== $order->id || (string) ($p['collector_id'] ?? '') !== $this->receiver->collector || ($p['site_id'] ?? '') !== 'MPE' || count($items) !== 1 || ($items[0]['currency_id'] ?? '') !== $order->currency || (string) ($items[0]['quantity'] ?? '') !== '1' || ProviderAmount::cents($items[0]['unit_price'] ?? null) !== (int) $order->amount_cents || ! self::checkoutUrl($p['init_point'] ?? '', $id)) {
            throw new \DomainException('preference_mismatch');
        }

        return ['id' => $id, 'url' => $p['init_point']];
    }

    public function create(object $order): array
    {
        $this->verifyOrder($order);
        $return = rtrim(config('payments.return_origin'), '/').(! empty($order->subscription_id) ? '/subscription' : '/documents/'.$order->document_id).'?payment_return=1';
        $payload = ['items' => [['id' => $order->id, 'title' => ! empty($order->subscription_id) ? 'Suscripción Revenia · Entorno de pruebas' : 'Pago de comprobante · Entorno de pruebas', 'quantity' => 1, 'currency_id' => $order->currency, 'unit_price' => '__REVENIA_EXACT_AMOUNT__']], 'external_reference' => $order->id, 'notification_url' => self::notificationUrl($this->receiver->company).'?source_news=webhooks', 'back_urls' => ['success' => $return, 'failure' => $return, 'pending' => $return], 'expires' => true, 'expiration_date_from' => now()->toIso8601String(), 'expiration_date_to' => CarbonImmutable::parse($order->expires_at)->toIso8601String()];
        $body = str_replace('"__REVENIA_EXACT_AMOUNT__"', (new Money((int) $order->amount_cents, Currency::from($order->currency)))->decimal(), json_encode($payload, JSON_THROW_ON_ERROR));
        // Preferences creation has no documented idempotency guarantee. Never automatically repeat this POST.
        $response = $this->client()->withBody($body, 'application/json')->post('/checkout/preferences');
        if (! $response->successful()) {
            throw new \RuntimeException('checkout_creation_uncertain');
        }

        return $this->preference(ProviderAmount::decode($response->body()), $order);
    }

    public function recover(object $order): ?array
    {
        $this->verifyOrder($order);
        if ($order->preference_id) {
            return $this->preference($this->get('/checkout/preferences/'.rawurlencode($order->preference_id)), $order);
        }
        $search = $this->get('/checkout/preferences/search', ['external_reference' => $order->id, 'limit' => 2]);
        if ((int) ($search['total'] ?? 0) === 0) {
            return null;
        }
        if ((int) $search['total'] !== 1 || count($search['elements'] ?? []) !== 1) {
            throw new \DomainException('multiple_preferences');
        }
        $id = $search['elements'][0]['id'] ?? '';
        if (! preg_match('/\A[a-zA-Z0-9-]{1,150}\z/', $id)) {
            throw new \DomainException('preference_mismatch');
        }

        return $this->preference($this->get('/checkout/preferences/'.$id), $order);
    }

    public function payment(string $id): array
    {
        if (! preg_match('/\A[0-9]{1,25}\z/', $id)) {
            throw new \DomainException('invalid_payment_id');
        }
        $p = $this->get('/v1/payments/'.$id);
        $merchant = $p['order'] ?? [];
        if (($merchant['type'] ?? null) !== 'mercadopago' || ! preg_match('/\A[0-9]{1,25}\z/', (string) ($merchant['id'] ?? ''))) {
            throw new \DomainException('integration_mismatch');
        }
        $m = $this->get('/merchant_orders/'.$merchant['id']);
        if ((string) ($m['id'] ?? '') !== (string) $merchant['id'] || ($m['external_reference'] ?? null) !== ($p['external_reference'] ?? null) || (string) ($m['collector']['id'] ?? '') !== $this->receiver->collector) {
            throw new \DomainException('merchant_order_mismatch');
        }
        $p['preference_id'] = $m['preference_id'] ?? null;

        return $p;
    }

    public function paymentIds(object $order): array
    {
        $r = $this->get('/v1/payments/search', ['external_reference' => $order->id, 'sort' => 'date_created', 'criteria' => 'desc', 'limit' => 50, 'offset' => 0]);
        if (! isset($r['paging']['total']) || ! ctype_digit((string) $r['paging']['total']) || ! is_array($r['results'] ?? null)) {
            throw new \DomainException('payment_search_mismatch');
        }
        if ((int) ($r['paging']['total'] ?? 0) > 50) {
            throw new \DomainException('payment_search_limit');
        }
        $ids = [];
        foreach ($r['results'] ?? [] as $p) {
            if (($p['external_reference'] ?? null) !== $order->id || ! preg_match('/\A[0-9]{1,25}\z/', (string) ($p['id'] ?? ''))) {
                throw new \DomainException('payment_search_mismatch');
            }$ids[] = (string) $p['id'];
        }

        return $ids;
    }
}
