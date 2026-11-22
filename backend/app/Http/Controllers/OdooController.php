<?php

namespace App\Http\Controllers;

use App\Actions\Odoo\OdooTransfers;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\OdooGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OdooController extends Controller
{
    private function owner(Request $request, bool $lock = false): User
    {
        $actor = $request->user();
        if ($lock) {
            $company = Company::whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
            $actor = User::where('company_id', $company->id)->sharedLock()->findOrFail($actor->id);
            $actor->setRelation('company', $company);
        }
        Gate::forUser($actor)->authorize('update', $actor->company);

        return $actor;
    }

    public function show(Request $request): JsonResponse
    {
        $actor = $this->owner($request);
        $row = DB::table('odoo_connections')->where('company_id', $actor->company_id)->first(['endpoint', 'remote_company', 'login', 'remote_name', 'destination', 'revision', 'enabled', 'verified_at']);
        $mappings = $row ? DB::table('odoo_mappings')->where('company_id', $actor->company_id)->where('destination', $row->destination)
            ->orderBy('kind')->orderBy('source')->get(['kind', 'source', 'remote_id']) : [];

        return response()->json(['data' => ['available' => (bool) config('odoo.enabled'), 'endpoints' => array_keys(config('odoo.endpoints')),
            'tenant' => $actor->company_id, 'connection' => $row, 'mappings' => $mappings]])->header('Cache-Control', 'private, no-store');
    }

    public function configure(Request $request, OdooGateway $gateway): JsonResponse
    {
        $actor = $this->owner($request);
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:80', Rule::in(array_keys(config('odoo.endpoints')))],
            'remote_company' => 'required|integer|min:1|max:2147483647', 'login' => 'required|string|max:255', 'api_key' => 'nullable|string|max:1024',
            'revision' => 'nullable|uuid', 'company_id' => 'missing']);
        abort_unless(config('odoo.enabled'), 409, 'El despliegue tiene desactivada la integración.');
        $previous = DB::table('odoo_connections')->where('company_id', $actor->company_id)->first();
        $sameUser = $previous && $previous->endpoint === $data['endpoint'] && $previous->login === $data['login'] && $previous->remote_company === (int) $data['remote_company'];
        if ($sameUser && ! OdooGateway::matchesDestination($previous)) {
            $sameUser = false;
        }
        $secret = ! empty($data['api_key']) ? Crypt::encryptString($data['api_key']) : null;
        if (! $secret && ! $sameUser) {
            throw ValidationException::withMessages(['api_key' => 'Ingresa una clave API para este destino y usuario.']);
        }
        $values = ['company_id' => $actor->company_id, 'endpoint' => $data['endpoint'], 'login' => $data['login'],
            'remote_company' => (int) $data['remote_company'], 'secret' => $secret ?? $previous->secret];
        $verified = $this->probe($gateway, (object) $values);
        DB::transaction(function () use ($request, $data, $values, $verified): void {
            $actor = $this->owner($request, true);
            $q = DB::table('odoo_connections')->where('company_id', $actor->company_id);
            $current = $q->lockForUpdate()->first();
            abort_if($current?->revision !== ($data['revision'] ?? null), 409, 'La conexión cambió. Actualiza la pantalla.');
            $changed = $current && $current->destination !== $verified['destination'];
            if ($changed) {
                $affected = DB::table('odoo_transfers')->where('company_id', $actor->company_id)->where('status', '<>', 'done');
                DB::table('odoo_events')->insertUsing(['id', 'company_id', 'transfer_id', 'destination', 'destination_name', 'actor_id', 'event', 'code', 'created_at'],
                    (clone $affected)->selectRaw("gen_random_uuid(),company_id,id,destination,destination_name,?::uuid,'odoo_review','odoo_destination',now()", [$actor->id]));
                $affected->update(['status' => 'review', 'error_code' => 'odoo_destination', 'token' => null, 'lease_until' => null, 'published_at' => null, 'available_at' => null,
                    'revision' => DB::raw('revision+1'), 'first_error_at' => DB::raw('coalesce(first_error_at,now())'), 'updated_at' => now()]);
            }
            $q->updateOrInsert(['company_id' => $actor->company_id], $values + $verified + ['enabled' => ! $changed && (bool) $current?->enabled,
                'revision' => (string) Str::uuid(), 'verified_at' => now(), 'updated_at' => now()]);
        });

        return $this->show($request);
    }

    private function probe(OdooGateway $gateway, object $connection): array
    {
        try {
            return $gateway->verify($connection);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['connection' => match ($e->getMessage()) {
                'odoo_credentials' => 'Odoo rechazó el usuario o la clave API.',
                'odoo_mapping' => 'La compañía debe tener un diario de ventas y una cuenta de ingresos visibles para el usuario técnico.',
                'odoo_endpoint' => 'El despliegue no habilitó este destino.',
                'odoo_unavailable' => 'No se pudo confirmar la conexión con Odoo.',
                default => 'Verifica Odoo 18, el módulo de Revenia, el UUID de empresa y los permisos de facturación.',
            }]);
        }
    }

    public function toggle(Request $request, OdooGateway $gateway): JsonResponse
    {
        $actor = $this->owner($request);
        $data = $request->validate(['enabled' => 'required|boolean', 'revision' => 'required|uuid', 'company_id' => 'missing']);
        $current = DB::table('odoo_connections')->where('company_id', $actor->company_id)->firstOrFail();
        if ($data['enabled']) {
            abort_unless(config('odoo.enabled'), 409, 'El despliegue tiene desactivada la integración.');
            abort_unless(OdooGateway::matchesDestination($current), 409, 'El destino autorizado cambió. Verifica una nueva clave API.');
            $verified = $this->probe($gateway, $current);
            abort_unless($verified['destination'] === $current->destination, 409, 'Verifica y guarda el nuevo destino antes de habilitarlo.');
        }
        DB::transaction(function () use ($request, $data, $current): void {
            $actor = $this->owner($request, true);
            $values = ['enabled' => (bool) $data['enabled'], 'revision' => (string) Str::uuid(), 'updated_at' => now()];
            if ($data['enabled']) {
                $values['verified_at'] = now();
            }
            abort_unless($current->revision === $data['revision'] && DB::table('odoo_connections')->where('company_id', $actor->company_id)
                ->where('revision', $current->revision)->update($values), 409);
        });

        return $this->show($request);
    }

    public function mapping(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => 'required|in:customer,anonymous,currency,unit,tax,account,journal', 'source' => 'required|string|max:80',
            'remote_id' => 'required|integer|min:1|max:2147483647', 'revision' => 'required|uuid', 'company_id' => 'missing']);
        DB::transaction(function () use ($request, $data): void {
            $actor = $this->owner($request, true);
            $c = DB::table('odoo_connections')->where('company_id', $actor->company_id)->where('revision', $data['revision'])->firstOrFail();
            $source = $data['source'];
            $allowed = match ($data['kind']) {
                'anonymous' => $source === 'anonymous',
                'customer' => Str::isUuid($source) && DB::table('customers')->where('company_id', $actor->company_id)->where('id', $source)->exists(),
                'currency' => in_array($source, ['PEN', 'USD'], true),'unit' => in_array($source, ['NIU', 'ZZ'], true),
                'tax' => in_array($source, ['10', '20', '30'], true),'account' => $source === 'income','journal' => $source === 'sales',
            };
            abort_unless($allowed, 422, 'La referencia de origen no corresponde a la empresa o al tipo elegido.');
            $key = ['company_id' => $actor->company_id, 'destination' => $c->destination, 'kind' => $data['kind'], 'source' => $source];
            if ($request->isMethod('delete')) {
                abort_unless(DB::table('odoo_mappings')->where($key)->where('remote_id', $data['remote_id'])->delete(), 409);
            } else {
                DB::table('odoo_mappings')->updateOrInsert($key, ['remote_id' => (int) $data['remote_id']]);
            }
        });

        return $this->show($request);
    }

    public function document(Request $request, string $documentId): JsonResponse
    {
        $actor = $request->user();
        $doc = Document::where('company_id', $actor->company_id)->findOrFail($documentId);
        Gate::authorize('view', $doc);
        $c = DB::table('odoo_connections')->where('company_id', $actor->company_id)->first(['destination', 'remote_name', 'enabled']);
        $transfer = DB::table('odoo_transfers')->where('company_id', $actor->company_id)->where('document_id', $documentId)
            ->first(['id', 'status', 'destination_name', 'destination', 'external_id', 'accounting_state', 'error_code', 'updated_at', 'may_exist', 'revision']);
        $can = $doc->status === 'accepted' && config('odoo.enabled') && $c?->enabled && $actor->can('transferToOdoo', $doc);

        return response()->json(['data' => ['transfer' => $transfer, 'can_queue' => $can && ! $transfer,
            'can_rebind' => $can && $transfer?->status === 'review' && ! $transfer->may_exist && $transfer->destination !== $c->destination,
            'current_destination' => $c?->destination, 'current_name' => $c?->remote_name, 'active' => (bool) (config('odoo.enabled') && $c?->enabled)]])->header('Cache-Control', 'private, no-store');
    }

    public function queue(Request $request, string $documentId): JsonResponse
    {
        $data = $request->validate(['rebind' => 'sometimes|boolean', 'revision' => 'required_if:rebind,true|integer|min:1', 'destination' => 'required_if:rebind,true|string|size:64', 'company_id' => 'missing']);
        DB::transaction(function () use ($request, $documentId, $data): void {
            $actor = $this->owner($request, true);
            $doc = Document::where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($documentId);
            Gate::forUser($actor)->authorize('transferToOdoo', $doc);
            abort_unless($doc->status === 'accepted', 409);
            if ($data['rebind'] ?? false) {
                $c = DB::table('odoo_connections')->where('company_id', $actor->company_id)->firstOrFail();
                abort_unless($c->destination === ($data['destination'] ?? null), 409);
                $row = DB::table('odoo_transfers')->where('company_id', $actor->company_id)->where('document_id', $doc->id)->firstOrFail();
                abort_unless($row->revision === (int) $data['revision'], 409);
                OdooTransfers::retry($actor, $row->id, true);
            } else {
                OdooTransfers::enqueue($doc, $actor);
            }
        });

        return $this->document($request, $documentId)->setStatusCode(202);
    }
}
