<?php

namespace App\Http\Controllers;

use App\Actions\Payments\Collections;
use App\Models\Company;
use App\Models\User;
use App\Services\MercadoPagoGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MerchantConnectionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('view', $request->user()->company);
        $row = DB::table('merchant_connections')->where('company_id', $request->user()->company_id)
            ->orderByDesc('active')->orderByDesc('updated_at')->first(['id', 'active', 'nickname', 'collector_id', 'verified_at', 'verification_error']);

        return response()->json(['data' => [
            'status' => $row ? ($row->active ? 'connected' : ($row->verification_error ? 'invalid' : 'disabled')) : 'unlinked',
            'collector_id' => $row?->collector_id, 'nickname' => $row?->nickname, 'verified_at' => $row?->verified_at,
            'environment' => 'sandbox', 'ready' => MercadoPagoGateway::transportReady(),
            'webhook_url' => MercadoPagoGateway::transportReady() ? MercadoPagoGateway::notificationUrl($request->user()->company_id) : null,
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function link(Request $request): JsonResponse
    {
        $actor = $request->user();
        Gate::authorize('update', $actor->company);
        $data = $request->validate(['access_token' => ['required', 'string', 'min:16', 'max:2048'],
            'webhook_secret' => ['required', 'string', 'min:16', 'max:512'], 'company_id' => ['missing']]);
        try {
            $identity = MercadoPagoGateway::identify($data['access_token']);
        } catch (\DomainException) {
            $this->record($actor, 'connection_verification_failed', null, 'test_receiver_unverified');
            throw ValidationException::withMessages(['access_token' => 'Verifica el token de la cuenta vendedora de pruebas de Perú.']);
        } catch (\RuntimeException) {
            $this->record($actor, 'connection_verification_failed', null, 'provider_unavailable');
            Collections::error('provider_unavailable', 'Mercado Pago no pudo verificar la cuenta. Reintenta más tarde.', 503);
        }
        DB::transaction(function () use ($actor, $identity, $data): void {
            $this->lockOwner($actor);
            $id = $identity['collector_id'];
            if ($id === (string) config('payments.collector_id') || DB::table('payment_orders')->where('receiver_kind', 'platform')->where('collector_id', $id)->exists()) {
                throw ValidationException::withMessages(['access_token' => 'Esta cuenta está reservada para las suscripciones a Revenia.']);
            }
            $attributes = ['verification_error' => null, 'nickname' => $identity['nickname'], 'access_token' => Crypt::encryptString($data['access_token']),
                'webhook_secret' => Crypt::encryptString($data['webhook_secret']), 'verified_at' => now(), 'updated_at' => now()];
            $connections = DB::table('merchant_connections')->where('company_id', $actor->company_id);
            $existing = (clone $connections)->where('collector_id', $id)->first();
            $connection = $existing?->id ?? (string) Str::uuid();
            if (! $existing && ! DB::table('merchant_connections')->insertOrIgnore($attributes + [
                'id' => $connection, 'company_id' => $actor->company_id, 'collector_id' => $id, 'active' => false,
            ])) {
                throw ValidationException::withMessages(['access_token' => 'Este receptor no está disponible para la empresa.']);
            }
            $previous = (clone $connections)->where('active', true)->where('id', '<>', $connection)->first();
            if ($previous) {
                $this->disable($actor, $previous->id);
            }
            $connections->where('id', $connection)->update($attributes + ['active' => true]);
            $this->record($actor, 'connection_verified', $connection);
        });

        return $this->show($request);
    }

    public function destroy(Request $request): JsonResponse
    {
        $actor = $request->user();
        DB::transaction(function () use ($actor): void {
            $this->lockOwner($actor);
            $id = DB::table('merchant_connections')->where('company_id', $actor->company_id)->where('active', true)->value('id');
            if ($id) {
                $this->disable($actor, $id);
            }
        });

        return $this->show($request);
    }

    private function lockOwner(User $actor): void
    {
        $company = Company::whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
        $current = User::where('company_id', $company->id)->whereKey($actor->id)->sharedLock()->firstOrFail();
        Gate::forUser($current)->authorize('update', $company);
    }

    private function disable(User $actor, string $connection): void
    {
        DB::table('merchant_connections')->where('company_id', $actor->company_id)->where('id', $connection)->update(['active' => false, 'verification_error' => null, 'updated_at' => now()]);
        DB::table('payment_orders')->where('company_id', $actor->company_id)->where('connection_id', $connection)
            ->whereIn('status', ['pending', 'open'])->update(['status' => 'stale', 'updated_at' => now()]);
        $this->record($actor, 'connection_disabled', $connection);
    }

    private function record(User $actor, string $event, ?string $connection, ?string $code = null): void
    {
        DB::table('payment_audit')->insert(['id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'company_id' => $actor->company_id,
            'connection_id' => $connection, 'event' => $event, 'code' => $code, 'created_at' => now()]);
    }
}
