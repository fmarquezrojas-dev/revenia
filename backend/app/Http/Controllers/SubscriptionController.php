<?php

namespace App\Http\Controllers;

use App\Actions\Payments\Collections;
use App\Actions\Subscriptions\Subscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SubscriptionController extends Controller
{
    public function plans(): JsonResponse
    {
        Gate::authorize('manage-subscription');

        return response()->json(['data' => DB::table('plans')->where('available', true)->orderBy('price_cents')->get(['code', 'name', 'description', 'price_cents', 'currency', 'emission_limit'])]);
    }

    public function usage(Request $r, Subscriptions $s): JsonResponse
    {
        Gate::authorize('view-subscription');

        return response()->json(['data' => $s->usage($r->user()->company_id)])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, Subscriptions $s): JsonResponse
    {
        Gate::authorize('manage-subscription');

        return response()->json(['data' => $s->detail($r->user()->company_id)])->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $r, Subscriptions $s): JsonResponse
    {
        return $this->show($r, $s);
    }

    public function intent(Request $r, string $subscriptionId, Subscriptions $s): JsonResponse
    {
        DB::table('subscriptions')->where('company_id', $r->user()->company_id)->where('id', $subscriptionId)->firstOrFail();
        Gate::authorize('manage-subscription');

        return response()->json(['data' => collect($s->detail($r->user()->company_id, $subscriptionId)['history'])->firstWhere('id', $subscriptionId)]);
    }

    public function store(Request $r, Subscriptions $s): JsonResponse
    {
        Gate::authorize('manage-subscription');
        $r->validate(['plan_code' => 'required|string|max:40']);
        if (array_keys($r->all()) !== ['plan_code']) {
            Collections::error('invalid_payload', 'Solo se admite plan_code.', 422);
        }$key = $r->header('Idempotency-Key', '');
        if (! preg_match('/\A[a-zA-Z0-9_-]{16,100}\z/', $key)) {
            Collections::error('invalid_idempotency_key', 'Clave de idempotencia invalida.', 422);
        }

        return response()->json($s->purchase($r->user(), $r->string('plan_code')->toString(), $key), 202);
    }

    public function cancel(Request $r, string $subscriptionId, Subscriptions $s): JsonResponse
    {
        DB::table('subscriptions')->where('company_id', $r->user()->company_id)->where('id', $subscriptionId)->firstOrFail();
        Gate::authorize('manage-subscription');
        $key = $r->header('Idempotency-Key', '');
        if ($r->all() !== [] || ! preg_match('/\A[a-zA-Z0-9_-]{16,100}\z/', $key)) {
            Collections::error('invalid_payload', 'Cuerpo vacio y clave valida requeridos.', 422);
        }

        return response()->json($s->cancel($r->user(), $subscriptionId, $key));
    }
}
