<?php

namespace App\Http\Controllers;

use App\Actions\Payments\Collections;
use App\Services\MercadoPagoGateway;
use App\Services\MercadoPagoSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MercadoPagoWebhookController extends Controller
{
    public function __invoke(Request $request, MercadoPagoSignature $signature): JsonResponse
    {
        abort_unless(MercadoPagoGateway::configured(), 503, 'Pagos de prueba deshabilitados.');
        abort_if(strlen($request->getContent()) > 16384, 413);
        abort_unless($request->isJson(), 415);
        $payment = $signature->paymentId($request);
        try {
            $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(400, 'JSON invalido.');
        }
        abort_unless(is_array($body) && is_scalar($body['id'] ?? null) && preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', (string) $body['id']) && (string) ($body['data']['id'] ?? '') === $payment, 400);
        abort_unless(($body['type'] ?? null) === 'payment' && in_array($body['action'] ?? null, ['payment.created', 'payment.updated'], true), 422, 'Evento no admitido.');
        abort_unless(($body['live_mode'] ?? null) === false, 422, 'Solo pagos de prueba.');
        // The body is not signed in full. Dedup includes the signed payment identity; lookup remains authoritative.
        DB::transaction(fn () => Collections::enqueue('payment', null, null, $payment, 'webhook:'.$body['id'].':'.$payment, (string) $body['id']));

        return response()->json(['received' => true], 200);
    }
}
