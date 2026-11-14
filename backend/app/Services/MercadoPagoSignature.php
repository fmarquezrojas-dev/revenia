<?php

namespace App\Services;

use Illuminate\Http\Request;

final class MercadoPagoSignature
{
    public function paymentId(Request $request): string
    {
        // Read the raw query: PHP normalizes data.id to data_id; reject ambiguous duplicates.
        $ids = [];
        foreach (explode('&', $request->server('QUERY_STRING', '')) as $part) {
            $pair = explode('=', $part, 2);
            if (urldecode($pair[0]) === 'data.id') {
                $ids[] = urldecode($pair[1] ?? '');
            }
        }
        abort_unless(count($ids) === 1 && preg_match('/\A[0-9]{1,25}\z/', $ids[0]), 400, 'Identificador invalido.');
        $parts = [];
        foreach (explode(',', $request->header('x-signature', '')) as $part) {
            $pair = explode('=', trim($part), 2);
            abort_unless(count($pair) === 2 && ! isset($parts[$pair[0]]), 401);
            $parts[$pair[0]] = $pair[1];
        }
        $requestId = $request->header('x-request-id', '');
        $ts = $parts['ts'] ?? '';
        $digest = $parts['v1'] ?? '';
        abort_unless(preg_match('/\A[a-zA-Z0-9-]{1,150}\z/', $requestId) && preg_match('/\A[0-9]{1,20}\z/', $ts) && preg_match('/\A[a-fA-F0-9]{64}\z/', $digest), 401);
        $expected = hash_hmac('sha256', 'id:'.$ids[0].';request-id:'.$requestId.';ts:'.$ts.';', config('payments.webhook_secret'));
        abort_unless(hash_equals($expected, strtolower($digest)), 401);

        // No arbitrary age cutoff: provider retries include multi-day intervals.
        return $ids[0];
    }
}
