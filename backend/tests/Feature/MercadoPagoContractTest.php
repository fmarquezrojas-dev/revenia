<?php

namespace Tests\Feature;

use App\Domain\Money\ProviderAmount;
use App\Services\MercadoPagoGateway;
use App\Services\MercadoPagoSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MercadoPagoContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['payments.enabled' => true, 'payments.access_token' => 'synthetic-token-not-real', 'payments.collector_id' => '123', 'payments.webhook_secret' => 'synthetic-webhook-secret', 'payments.notification_url' => 'https://demo.example.test/api/v1/webhooks/mercadopago', 'payments.return_origin' => 'https://demo.example.test']);
    }

    public function test_json_numeric_tokens_are_preserved_without_binary_float(): void
    {
        $v = ProviderAmount::decode('{"transaction_amount":90071992547409.91,"id":12345678901234567890,"live_mode":false,"text":"1.20 and \"3\""}');
        $this->assertSame('90071992547409.91', $v['transaction_amount']);
        $this->assertSame(9007199254740991, ProviderAmount::cents($v['transaction_amount']));
        $this->assertSame('12345678901234567890', $v['id']);
        $this->assertFalse($v['live_mode']);
    }

    #[TestWith([1.20])]
    #[TestWith(['1e2'])]
    #[TestWith(['-1'])]
    #[TestWith(['1.001'])]
    #[TestWith(['90071992547409.92'])]
    public function test_invalid_amounts_are_rejected(mixed $v): void
    {
        $this->expectException(\DomainException::class);
        ProviderAmount::cents($v);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_creation_checks_receiver_and_sends_exact_amount_once(bool $subscription): void
    {
        $o = (object) ['id' => 'order-id', 'document_id' => $subscription ? null : 'document-id', 'subscription_id' => $subscription ? 'subscription-id' : null, 'currency' => 'PEN', 'amount_cents' => 4065, 'expires_at' => now()->addDay(), 'preference_id' => null];
        Http::fake(['https://api.mercadolibre.com/users/me' => Http::response(['id' => 123, 'site_id' => 'MPE', 'tags' => ['test_user']]), 'https://api.mercadopago.com/checkout/preferences' => Http::response('{"id":"123-test","external_reference":"order-id","collector_id":123,"site_id":"MPE","items":[{"currency_id":"PEN","quantity":1,"unit_price":40.65}],"init_point":"https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=123-test"}', 201)]);
        $p = app(MercadoPagoGateway::class)->create($o);
        $this->assertSame('123-test', $p['id']);
        $return = 'https://demo.example.test'.($subscription ? '/subscription' : '/documents/document-id').'?payment_return=1';
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['back_urls']['success'] === $return && $r['back_urls']['pending'] === $return && $r['back_urls']['failure'] === $return);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->body(), '"unit_price":40.65') && ! $r->hasHeader('X-Idempotency-Key'));
    }

    public function test_production_receiver_never_reaches_preference_creation(): void
    {
        Http::fake(['https://api.mercadolibre.com/users/me' => Http::response(['id' => 123, 'site_id' => 'MPE', 'tags' => []])]);
        try {
            app(MercadoPagoGateway::class)->create((object) []);
            $this->fail('Production receiver accepted');
        } catch (\DomainException $e) {
            $this->assertSame('test_receiver_unverified', $e->getMessage());
        }Http::assertSentCount(1);
    }

    public function test_payment_is_joined_to_authoritative_merchant_order(): void
    {
        Http::fake(['https://api.mercadopago.com/v1/payments/500' => Http::response(['id' => 500, 'external_reference' => 'reference', 'order' => ['id' => 700, 'type' => 'mercadopago']]), 'https://api.mercadopago.com/merchant_orders/700' => Http::response(['id' => 700, 'external_reference' => 'reference', 'collector' => ['id' => 123], 'preference_id' => '123-test'])]);
        $this->assertSame('123-test', app(MercadoPagoGateway::class)->payment('500')['preference_id']);
        Http::assertSentCount(2);
    }

    public function test_signature_accepts_delayed_retry_but_rejects_query_substitution(): void
    {
        $r = Request::create('/?data.id=500');
        $r->headers->set('x-request-id', 'request-1');
        $r->headers->set('x-signature', 'ts=1700000000,v1='.hash_hmac('sha256', 'id:500;request-id:request-1;ts:1700000000;', config('payments.webhook_secret')));
        $this->assertSame('500', app(MercadoPagoSignature::class)->paymentId($r));
        $r->server->set('QUERY_STRING', 'data.id=500&data.id=501');
        $this->expectException(HttpException::class);
        app(MercadoPagoSignature::class)->paymentId($r);
    }

    #[TestWith(['https://evil.test/checkout/v1/redirect?pref_id=a'])]
    #[TestWith(['javascript:alert(1)'])]
    #[TestWith(['https://www.mercadopago.com.pe@evil.test/checkout/v1/redirect?pref_id=a'])]
    public function test_untrusted_checkout_urls_are_rejected(string $url): void
    {
        $this->assertFalse(MercadoPagoGateway::checkoutUrl($url, 'a'));
    }

    #[TestWith([[]])]
    #[TestWith([['paging' => ['total' => 51], 'results' => []]])]
    #[TestWith([['paging' => ['total' => 1], 'results' => [['id' => 500, 'external_reference' => 'wrong']]]])]
    public function test_incomplete_or_ambiguous_search_never_reports_reconciliation_success(array $response): void
    {
        Http::fake(['https://api.mercadopago.com/v1/payments/search*' => Http::response($response)]);
        $this->expectException(\DomainException::class);
        app(MercadoPagoGateway::class)->paymentIds((object) ['id' => 'reference']);
    }

    public function test_payment_search_returns_only_validated_identifiers(): void
    {
        Http::fake(['https://api.mercadopago.com/v1/payments/search*' => Http::response(['paging' => ['total' => 1], 'results' => [['id' => 500, 'external_reference' => 'reference']]])]);
        $this->assertSame(['500'], app(MercadoPagoGateway::class)->paymentIds((object) ['id' => 'reference']));
    }
}
