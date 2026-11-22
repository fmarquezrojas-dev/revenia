<?php

namespace App\Http\Controllers;

use App\Actions\Payments\Collections;
use App\Services\MercadoPagoSignature;
use App\Services\PaymentReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MercadoPagoWebhookController extends Controller
{
    public function __invoke(Request $request, MercadoPagoSignature $signature, ?string $merchantCompany = null): JsonResponse
    {

        abort_if(strlen($request->getContent()) > 16384, 413);
        abort_unless($request->isJson(), 415);
        try {
            $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\Throwable) {
            abort(400, 'JSON invalido.');
        }
        abort_unless(is_array($body), 400);
        $connection = null;
        if ($merchantCompany !== null) {
            $collector = $body['user_id'] ?? null;
            abort_unless(is_scalar($collector) && preg_match('/\A[0-9]{1,25}\z/', (string) $collector), 400);
            $connection = DB::table('merchant_connections')->where('company_id', $merchantCompany)
                ->where('collector_id', (string) $collector)->value('id');
            abort_unless($connection, 401);
        }
        try {
            $receiver = PaymentReceiver::forWebhook($connection);
        } catch (\DomainException) {
            abort(503, 'Receptor de notificaciones no disponible.');
        }
        $payment = $signature->paymentId($request, $receiver->signingSecret());
        abort_unless(is_scalar($body['id'] ?? null) && preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', (string) $body['id']) && (string) ($body['data']['id'] ?? '') === $payment, 400);
        abort_unless(($body['type'] ?? null) === 'payment' && in_array($body['action'] ?? null, ['payment.created', 'payment.updated'], true), 422, 'Evento no admitido.');
        abort_unless(($body['live_mode'] ?? null) === false, 422, 'Solo pagos de prueba.');
        // The body is not signed in full. Dedup includes the signed payment identity; lookup remains authoritative.
        DB::transaction(fn () => Collections::enqueue('payment', $receiver->company, null, $payment, 'webhook:'.($connection ?? 'platform').':'.$body['id'].':'.$payment, (string) $body['id'], $receiver->kind, $connection, $receiver->collector));

        return response()->json(['received' => true], 200);
    }
}
