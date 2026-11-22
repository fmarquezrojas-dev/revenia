<?php

namespace App\Actions\Payments;

use App\Models\Document;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class FollowupDetail
{
    public function read(User $user, Document $doc, int $page, string $today): array
    {
        $balance = CollectionFollowup::balance($doc);
        $state = CollectionFollowup::scoped('collection_followups', $doc)->first();
        $assignee = $state?->assignee_id ? User::where('company_id', $doc->company_id)->find($state->assignee_id, ['id', 'name', 'role', 'is_active']) : null;
        $gate = Gate::forUser($user);
        $assign = $gate->allows('assignCollection', $doc);
        $promise = CollectionFollowup::active($doc);
        $events = DB::table('collection_followup_events as e')->where('e.company_id', $doc->company_id)->where('e.document_id', $doc->id)
            ->join('users as a', fn ($j) => $j->on('a.id', 'e.actor_id')->on('a.company_id', 'e.company_id'))
            ->select('e.id', 'e.kind', 'e.details', 'e.recorded_at', 'e.revision', 'e.corrects_id', 'e.promise_id', 'a.name as author');
        $total = (clone $events)->count();
        $lastPage = max(1, (int) ceil($total / 20));
        $page = min($lastPage, $page);
        $history = (clone $events)->orderByDesc('e.revision')->forPage($page, 20)->get();
        $corrections = (clone $events)->whereIn('e.corrects_id', $history->where('kind', 'contact')->pluck('id'))
            ->distinct('e.corrects_id')->orderBy('e.corrects_id')->orderByDesc('e.revision')->get()->keyBy('corrects_id');
        $closed = PromiseEvidence::promises($doc->company_id)->where('c.document_id', $doc->id)->where('c.status', 'fulfilled')
            ->whereIn('c.id', $history->where('kind', 'fulfill')->pluck('promise_id'))->get()->keyBy('id');
        foreach ($history as $event) {
            $event->recorded_at = CarbonImmutable::parse($event->recorded_at)->toISOString();
            $event->details = json_decode($event->details);
            if ($corrections->has($event->id)) {
                $event->current_details = json_decode($corrections[$event->id]->details);
            }
            if ($event->kind === 'fulfill' && ($saved = $closed->get($event->promise_id))) {
                $event->evidence_review = $saved->cash_cents < $saved->amount_cents || $balance->needs_review;
                $event->confirmed_cents = (string) $saved->cash_cents;
            }
        }

        $contact = (clone $events)->where('e.kind', 'contact')->orderByDesc('e.revision')->first();
        if ($contact) {
            $contact->recorded_at = CarbonImmutable::parse($contact->recorded_at)->toISOString();
            $correction = (clone $events)->where('e.corrects_id', $contact->id)->orderByDesc('e.revision')->first();
            $contact->details = json_decode(($correction ?? $contact)->details);
        }

        return ['id' => $doc->id, 'number' => $doc->series_code.'-'.str_pad((string) $doc->number, 8, '0', STR_PAD_LEFT),
            'customer' => $doc->fiscal_snapshot['customer']['name'] ?? 'Sin cliente identificado', 'currency' => $doc->currency,
            'due_date' => $doc->due_date?->toDateString(), 'today' => $today,
            'outstanding_cents' => (string) $balance->outstanding_cents, 'paid_cents' => (string) $balance->paid_cents,
            'credits_cents' => (string) $balance->credits_cents, 'needs_review' => $balance->needs_review,
            'assignee' => $assignee, 'revision' => (int) ($state?->revision ?? 0), 'next_action_on' => $state?->next_action_on,
            'last_contact' => $contact, 'promise' => $promise ? PromiseEvidence::progress($promise, $balance, $today) : null,
            'can_assign' => $assign, 'can_manage' => $gate->allows('manageFollowup', [$doc, $assignee?->id]),
            'people' => $assign ? CollectionAgenda::people($user, true) : [], 'history' => $history,
            'page' => $page, 'last_page' => $lastPage];
    }
}
