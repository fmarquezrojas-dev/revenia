<?php

namespace App\Actions\Odoo;

use App\Jobs\TransferToOdoo;
use App\Models\Document;
use App\Models\User;
use App\Services\OdooGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OdooTransfers
{
    public function __construct(private OdooGateway $gateway) {}

    public static function enqueue(Document $doc, ?User $actor = null): void
    {
        if (! config('odoo.enabled')) {
            abort_if($actor !== null, 409);

            return;
        }
        $connection = DB::table('odoo_connections')->where('company_id', $doc->company_id)->sharedLock()->first();
        if (! $connection?->enabled) {
            abort_if($actor !== null, 409);

            return;
        }
        $id = (string) Str::uuid();
        if (DB::table('odoo_transfers')->insertOrIgnore(['id' => $id, 'company_id' => $doc->company_id, 'document_id' => $doc->id,
            'destination' => $connection->destination, 'destination_name' => $connection->remote_name, 'status' => 'queued',
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now()])) {
            self::event($doc->company_id, $id, 'odoo_queued', actor: $actor?->id);
        }
    }

    public static function event(string $company, string $transfer, string $event, ?string $code = null, ?string $actor = null): void
    {
        DB::table('odoo_events')->insertUsing(['id', 'company_id', 'transfer_id', 'destination', 'destination_name', 'actor_id', 'event', 'code', 'created_at'],
            DB::table('odoo_transfers')->where('company_id', $company)->where('id', $transfer)
                ->selectRaw('gen_random_uuid(),company_id,id,destination,destination_name,?::uuid,?::varchar,?::varchar,now()', [$actor, $event, $code]));
    }

    public static function retry(User $actor, string $id, bool $change = false): void
    {
        $connection = DB::table('odoo_connections')->where('company_id', $actor->company_id)->sharedLock()->first();
        $q = DB::table('odoo_transfers')->where('company_id', $actor->company_id)->where('id', $id);
        $row = $q->lockForUpdate()->firstOrFail();
        abort_unless(config('odoo.enabled') && $connection?->enabled && in_array($row->status, ['review', 'uncertain', 'dependency'], true), 409);
        $different = $row->destination !== $connection->destination;
        abort_if($change !== $different || ($different && $row->may_exist), 409);
        $update = ['status' => 'queued', 'available_at' => now(), 'published_at' => null, 'updated_at' => now(), 'error_code' => null,
            'revision' => DB::raw('revision+1'), 'attempts' => 0];
        if (! $row->may_exist) {
            $update['payload'] = null;
        }
        if ($different) {
            $update += ['destination' => $connection->destination, 'destination_name' => $connection->remote_name];
        }
        $q->update($update);
        self::event($actor->company_id, $id, $different ? 'odoo_rebound' : 'odoo_queued', actor: $actor->id);
    }

    public static function tick(): void
    {
        if (! config('odoo.enabled')) {
            return;
        }
        // Redis only dispatches identifiers. PostgreSQL owns eligibility, attempts and recovery.
        $eligible = DB::table('odoo_transfers as t')->join('odoo_connections as c', 'c.company_id', '=', 't.company_id')
            ->join('companies as tenant', 'tenant.id', '=', 't.company_id')->where('tenant.is_active', true)
            ->where('c.enabled', true)->whereColumn('c.destination', 't.destination')
            ->where(function ($q): void {
                $q->where(fn ($s) => $s->whereIn('t.status', ['queued', 'retry'])->where('t.available_at', '<=', now()))
                    ->orWhere(fn ($s) => $s->where('t.status', 'processing')->where('t.lease_until', '<=', now()))
                    ->orWhere(fn ($s) => $s->where('t.status', 'dependency')->whereExists(fn ($x) => $x->selectRaw('1')
                        ->from('documents as note')->join('odoo_transfers as orig', 'orig.document_id', '=', 'note.source_document_id')
                        ->whereColumn('note.id', 't.document_id')->whereColumn('orig.company_id', 't.company_id')
                        ->whereColumn('orig.destination', 't.destination')->where('orig.status', 'done')));
            })->where(fn ($q) => $q->whereNull('t.published_at')->orWhere('t.published_at', '<', now()->subMinutes(2)))
            ->orderBy('t.updated_at')->orderBy('t.id')->limit(50)->get(['t.company_id', 't.id']);
        foreach ($eligible as $row) {
            $claimed = DB::table('odoo_transfers')->where('company_id', $row->company_id)->where('id', $row->id)
                ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<', now()->subMinutes(2)))->update(['published_at' => now()]);
            if ($claimed) {
                TransferToOdoo::dispatch($row->company_id, $row->id)->onConnection('redis')->onQueue('odoo');
            }
        }
    }

    public function run(string $company, string $id): void
    {
        if (! config('odoo.enabled')) {
            return;
        }
        $scope = fn () => DB::table('odoo_transfers')->where('company_id', $company)->where('id', $id);
        $claim = DB::transaction(function () use ($scope, $company, $id) {
            $connection = DB::table('odoo_connections')->where('company_id', $company)->sharedLock()->first();
            $row = $scope()->lockForUpdate()->first();
            if (! $row || ! $connection?->enabled || $row->destination !== $connection->destination ||
                ! DB::table('companies')->where('id', $company)->where('is_active', true)->exists()) {
                return null;
            }
            $ready = in_array($row->status, ['queued', 'retry'], true) && $row->available_at && now()->gte($row->available_at);
            if (! $ready && $row->status !== 'dependency' && ! ($row->status === 'processing' && now()->gte($row->lease_until))) {
                return null;
            }
            if ($row->status === 'processing' && $row->attempts >= 4) {
                $status = $row->may_exist ? 'uncertain' : 'review';
                $scope()->update(['status' => $status, 'token' => null, 'lease_until' => null, 'published_at' => null, 'error_code' => 'odoo_unavailable',
                    'revision' => DB::raw('revision+1'), 'first_error_at' => DB::raw('coalesce(first_error_at,now())'), 'updated_at' => now()]);
                self::event($company, $id, 'odoo_'.$status, 'odoo_unavailable');

                return null;
            }
            $token = (string) Str::uuid();
            $scope()->update(['status' => 'processing', 'token' => $token, 'attempts' => DB::raw('attempts+1'), 'lease_until' => now()->addSeconds(90),
                'available_at' => null, 'updated_at' => now(), 'revision' => DB::raw('revision+1')]);
            self::event($company, $id, 'attempt_started');

            return [$row, $connection, $token];
        });
        if (! $claim) {
            return;
        }
        [$row,$connection,$token] = $claim;
        $owned = fn () => $scope()->where('token', $token)->where('lease_until', '>', now());
        $written = (bool) $row->may_exist;
        $creating = false;
        try {
            if (OdooGateway::fingerprint($connection, $connection->database_uuid) !== $row->destination) {
                throw new \DomainException('odoo_destination');
            }
            $doc = Document::where('company_id', $company)->findOrFail($row->document_id);
            $payload = $row->payload ? json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR) : OdooDocument::payload($doc, $connection);
            $session = $this->gateway->session($connection);
            $args = [$company, $connection->database_uuid];
            $receipt = $this->gateway->call($session, 'revenia_lookup', [...$args, $payload['key']]);
            if ($row->external_id && (! is_array($receipt) || ($receipt['id'] ?? null) !== (int) $row->external_id)) {
                throw new \DomainException('odoo_receipt');
            }
            if ($receipt === false) {
                $allowed = DB::transaction(function () use ($owned, $company, $connection, $payload): bool {
                    $live = DB::table('odoo_connections')->where('company_id', $company)->sharedLock()->first();

                    return $live?->enabled && $live->destination === $connection->destination &&
                        (bool) $owned()->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'may_exist' => true]);
                });
                if (! $allowed) {
                    throw new \DomainException('odoo_paused');
                }
                $creating = true;
                $this->gateway->call($session, 'revenia_import', [...$args, $payload]);
                $written = true;
                $creating = false;
                $receipt = $this->gateway->call($session, 'revenia_lookup', [...$args, $payload['key']]);
            } elseif (is_array($receipt)) {
                $written = true;
            }
            $external = OdooDocument::confirm($payload, $receipt);
            $status = 'done';
            $code = null;
            $details = ['external_id' => $external, 'accounting_state' => 'draft', 'may_exist' => true, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)];
        } catch (\Throwable $exception) {
            $code = $exception instanceof \DomainException ? $exception->getMessage() : 'odoo_unavailable';
            if ($creating && ! in_array($code, ['odoo_mapping', 'odoo_amounts', 'odoo_destination'], true)) {
                $written = true;
            }
            $status = match (true) {
                $code === 'odoo_original' => 'dependency',
                ! $written && $code === 'odoo_unavailable' && $row->attempts < 3 => 'retry',
                $written && in_array($code, ['odoo_unavailable', 'odoo_rejected', 'odoo_paused'], true) => 'uncertain',
                default => 'review',
            };
            $details = ['may_exist' => $written];
            if (isset($receipt, $payload) && is_array($receipt) && ($receipt['key'] ?? null) === $payload['key']
                && ($receipt['company'] ?? null) === $payload['company'] && is_int($receipt['id'] ?? null) && $receipt['id'] > 0
                && (! $row->external_id || $receipt['id'] === (int) $row->external_id)
                && in_array($receipt['state'] ?? null, ['draft', 'posted', 'cancel'], true)) {
                $details['external_id'] = $receipt['id'];
                $details['accounting_state'] = $receipt['state'];
            }
            if (! $written) {
                $details['payload'] = null;
            } elseif (isset($payload)) {
                $details['payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
            }
        }
        DB::transaction(function () use ($owned, $company, $id, $status, $code, $details, $row) {
            $changed = $owned()->update($details + ['status' => $status, 'error_code' => $code, 'token' => null, 'lease_until' => null, 'published_at' => null,
                'available_at' => $status === 'retry' ? now()->addSeconds(30 * ($row->attempts + 1)) : null,
                'first_error_at' => $code ? DB::raw('coalesce(first_error_at,now())') : $row->first_error_at, 'updated_at' => now(), 'revision' => DB::raw('revision+1')]);
            if ($changed) {
                self::event($company, $id, 'odoo_'.$status, $code);
            }
        });
    }
}
