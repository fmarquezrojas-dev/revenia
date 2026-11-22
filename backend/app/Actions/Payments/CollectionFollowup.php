<?php

namespace App\Actions\Payments;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class CollectionFollowup
{
    public const CHANNELS = ['phone', 'email', 'message', 'meeting', 'other'];

    public const RESULTS = ['contacted', 'no_answer', 'dispute', 'callback'];

    public static function document(User $actor, string $id, bool $lock = false): Document
    {
        $q = Document::where('company_id', $actor->company_id)->whereIn('doc_type', ['01', '03'])->where('status', 'accepted');
        $doc = ($lock ? $q->lockForUpdate() : $q)->findOrFail($id);
        Gate::forUser($actor)->authorize('view', $doc);

        return $doc;
    }

    public static function scoped(string $table, Document $doc): Builder
    {
        return DB::table($table)->where(['company_id' => $doc->company_id, 'document_id' => $doc->id]);
    }

    public static function balance(Document $doc): object
    {
        return DocumentBalances::query($doc->company_id, $doc->currency, $doc->id)->firstOrFail();
    }

    public static function active(Document $doc): ?object
    {
        return PromiseEvidence::promises($doc->company_id)->where('c.document_id', $doc->id)->where('c.status', 'active')->first();
    }

    public function record(User $session, string $id, string $action, array $data): void
    {
        DB::transaction(function () use ($session, $id, $action, $data) {
            $actor = User::where('company_id', $session->company_id)->sharedLock()->findOrFail($session->id);
            $doc = self::document($actor, $id, true);
            $scope = self::scoped('collection_followups', $doc);
            $state = $scope->first();
            $gate = Gate::forUser($actor);
            if ($action === 'assign') {
                $gate->authorize('assignCollection', $doc);
            } else {
                $gate->authorize('manageFollowup', [$doc, $state?->assignee_id]);
            }
            $revision = (int) ($state?->revision ?? 0);
            abort_if((int) $data['revision'] !== $revision, 409, 'El seguimiento cambió. Actualiza la ficha antes de continuar.');
            $balance = self::balance($doc);
            $existingAssignment = $action === 'assign' && $state !== null;
            if (! $existingAssignment && ! in_array($action, ['fulfill', 'cancel', 'correct'], true)) {
                abort_if($balance->outstanding_cents <= 0, 409, 'El comprobante ya no tiene saldo pendiente.');
            }

            $key = ['company_id' => $doc->company_id, 'document_id' => $doc->id];
            if (! $state) {
                DB::table('collection_followups')->insert($key);
            }
            $event = $key + ['id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'kind' => $action, 'revision' => $revision + 1];
            $changes = ['revision' => $revision + 1];
            if ($action === 'assign') {
                $target = isset($data['assignee_id']) ? User::where('company_id', $doc->company_id)->where('is_active', true)
                    ->whereIn('role', ['owner', 'issuer'])->sharedLock()->findOrFail($data['assignee_id']) : null;
                abort_if($state?->assignee_id === $target?->id, 422, 'Selecciona un responsable distinto.');
                $changes['assignee_id'] = $target?->id;
                $details = ['previous_id' => $state?->assignee_id, 'assignee_id' => $target?->id, 'name' => $target?->name];
            } elseif (in_array($action, ['contact', 'correct'], true)) {
                $details = array_intersect_key($data, array_flip(['channel', 'result', 'note', 'next_action_on', 'reason']));
                if ($action === 'correct') {
                    $original = self::scoped('collection_followup_events', $doc)->where('kind', 'contact')->where('id', $data['contact_id'])->firstOrFail();
                    $event['corrects_id'] = $original->id;
                    $details['corrected_revision'] = $original->revision;
                }
                $latest = self::scoped('collection_followup_events', $doc)->where('kind', 'contact')->orderByDesc('revision')->value('id');
                if ($action === 'contact' || $latest === $event['corrects_id']) {
                    $changes['next_action_on'] = $data['next_action_on'] ?? null;
                }
            } else {
                [$event['promise_id'], $details] = $this->agreement($doc, $balance, $action, $data);
            }

            $scope->update($changes);
            DB::table('collection_followup_events')->insert($event + ['details' => json_encode($details, JSON_THROW_ON_ERROR)]);
        }, 3);
    }

    private function agreement(Document $doc, object $balance, string $action, array $data): array
    {
        $promise = self::active($doc);
        $table = self::scoped('collection_promises', $doc);
        if ($action === 'promise') {
            abort_if($promise !== null, 409, 'Ya existe un compromiso activo.');
            $id = (string) Str::uuid();
        } else {
            abort_unless($promise && $promise->id === $data['promise_id'], 409, 'El compromiso activo cambió.');
            $id = $promise->id;
            $table->where('id', $id);
        }
        $progress = $promise ? PromiseEvidence::progress($promise, $balance, now('America/Lima')->toDateString()) : null;
        $details = $promise ? ['previous_amount_cents' => (string) $promise->amount_cents, 'previous_due_on' => $promise->due_on] : [];
        if (isset($data['reason'])) {
            $details['reason'] = $data['reason'];
        }
        if ($action === 'fulfill' || $action === 'cancel') {
            $update = ['closed_at' => DB::raw('clock_timestamp()'), 'status' => $action === 'fulfill' ? 'fulfilled' : 'cancelled'];
            if ($action === 'fulfill') {
                abort_unless($progress['can_fulfill'], 409, 'El acuerdo necesita pagos confirmados suficientes y un saldo sin revisión.');
                $update['evidence'] = json_encode(PromiseEvidence::allocations($doc->company_id, $doc->id, $id, (int) $promise->amount_cents), JSON_THROW_ON_ERROR);
            }
            $table->update($update);

            return [$id, $details + ['covered_cents' => $progress['covered_cents']]];
        }

        abort_unless($data['currency'] === $doc->currency, 422, 'La moneda debe coincidir con el comprobante.');
        $pending = (int) $data['amount_cents'];
        abort_if($pending > $balance->outstanding_cents, 422, 'El importe pendiente supera el saldo vigente.');
        abort_if($balance->needs_review, 409, 'Revisa el saldo financiero antes de acordar un importe.');
        $covered = (int) ($progress['covered_cents'] ?? 0);
        abort_if($promise && $covered >= $promise->amount_cents, 409, 'Los pagos ya cubren el acuerdo. Revisa su cumplimiento.');
        $amount = $pending + $covered;
        $terms = ['amount_cents' => $amount, 'due_on' => $data['due_on'], 'credits_at_terms' => $balance->credits_cents];
        if ($promise) {
            if (DocumentCash::bankInstalled()) {
                $terms['terms_reviewed_at'] = DB::raw('clock_timestamp()');
            }
            $table->update($terms);
        } else {
            DB::table('collection_promises')->insert($terms + ['id' => $id, 'company_id' => $doc->company_id, 'document_id' => $doc->id,
                'currency' => $doc->currency, 'opened_at' => DB::raw('clock_timestamp()'), 'preexisting' => PromiseEvidence::baseline($doc)]);
        }

        return [$id, $details + ['amount_cents' => (string) $amount, 'due_on' => $data['due_on'], 'covered_cents' => (string) $covered]];
    }
}
