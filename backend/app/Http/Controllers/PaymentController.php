<?php

namespace App\Http\Controllers;

use App\Actions\Payments\Collections;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    public function show(Request $request, string $documentId, Collections $collections): JsonResponse
    {
        $summary = DB::transaction(function () use ($request, $documentId, $collections) {
            $doc = Document::where('company_id', $request->user()->company_id)->whereKey($documentId)->sharedLock()->firstOrFail();
            Gate::authorize('view', $doc);

            return $collections->summary($doc, $request->user());
        });

        return response()->json(['data' => $summary])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, string $documentId, Collections $collections): JsonResponse
    {
        return $this->submit($request, $documentId, $collections, 'create');
    }

    public function reconcile(Request $request, string $documentId, Collections $collections): JsonResponse
    {
        return $this->submit($request, $documentId, $collections, 'reconcile');
    }

    private function submit(Request $request, string $documentId, Collections $collections, string $operation): JsonResponse
    {
        $doc = Document::where('company_id', $request->user()->company_id)->whereKey($documentId)->firstOrFail();
        Gate::authorize($operation === 'create' ? 'createPayment' : 'reconcilePayment', $doc);
        $order = null;
        if ($operation === 'reconcile' && $request->has('order_id')) {
            $order = $request->validate(['order_id' => ['required', 'uuid']])['order_id'];
        }
        if (array_diff(array_keys($request->all()), $operation === 'reconcile' ? ['order_id'] : []) !== []) {
            Collections::error('invalid_payload', 'Esta operacion no admite importes ni otros campos.', 422);
        }
        $key = $request->header('Idempotency-Key', '');
        if (! preg_match('/\A[a-zA-Z0-9_-]{16,100}\z/', $key)) {
            Collections::error('invalid_idempotency_key', 'Envia una clave de idempotencia valida.', 422);
        }

        return response()->json($collections->request($request->user(), $documentId, $key, $operation, $order), 202);
    }
}
