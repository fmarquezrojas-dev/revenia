<?php

namespace App\Http\Controllers;

use App\Actions\Payments\Collections;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    public function show(Request $request, string $documentId, Collections $collections): JsonResponse
    {
        $doc = Document::where('company_id', $request->user()->company_id)->whereKey($documentId)->firstOrFail();
        Gate::authorize('view', $doc);

        return response()->json(['data' => $collections->summary($doc, $request->user())])->header('Cache-Control', 'private, no-store');
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
        if ($request->all() !== []) {
            Collections::error('invalid_payload', 'Esta operacion no admite importes ni otros campos.', 422);
        }
        $key = $request->header('Idempotency-Key', '');
        if (! preg_match('/\A[a-zA-Z0-9_-]{16,100}\z/', $key)) {
            Collections::error('invalid_idempotency_key', 'Envia una clave de idempotencia valida.', 422);
        }

        return response()->json($collections->request($request->user(), $documentId, $key, $operation), 202);
    }
}
