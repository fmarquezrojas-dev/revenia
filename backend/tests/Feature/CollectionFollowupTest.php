<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

class CollectionFollowupTest extends DatabaseTestCase
{
    private User $owner;

    private Document $doc;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner);
        $this->doc = Document::factory()->create(['company_id' => $this->owner->company_id, 'created_by' => $this->owner->id,
            'total_cents' => 10000, 'exempt_cents' => 10000]);
        $this->doc->items()->first()->update(['total_cents' => 10000, 'base_cents' => 10000, 'unit_price_cents' => 10000]);
        $this->doc->update(['status' => 'accepted', 'series_code' => $this->doc->series->code, 'number' => 1, 'issued_at' => now(),
            'submission_id' => (string) Str::uuid(), 'fiscal_snapshot' => ['customer' => ['name' => 'Prueba aislada']]]);
        $this->url = '/api/v1/receivables/'.$this->doc->id.'/followup';
    }

    private function terms(int $amount = 6000): array
    {
        return ['amount_cents' => $amount, 'currency' => 'PEN', 'due_on' => now('America/Lima')->subDay()->toDateString()];
    }

    private function pay(int $amount): string
    {
        $order = (string) Str::uuid();
        $id = (string) Str::uuid();
        DB::table('payment_orders')->insert(['id' => $order, 'company_id' => $this->owner->company_id, 'document_id' => $this->doc->id,
            'requested_by' => $this->owner->id, 'amount_cents' => $amount, 'currency' => 'PEN', 'provider' => 'test-history',
            'status' => 'closed', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->insert(['id' => $id, 'order_id' => $order, 'provider' => 'test-history', 'provider_id' => substr($id, 0, 24),
            'company_id' => $this->owner->company_id, 'document_id' => $this->doc->id, 'currency' => 'PEN', 'status' => 'approved',
            'amount_cents' => $amount, 'applied_cents' => $amount, 'confirmed_at' => DB::raw('clock_timestamp()'),
            'provider_updated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_agreement_tracks_only_later_cash_and_rechecks_closed_evidence(): void
    {
        $this->pay(1000);
        $this->postJson($this->url.'/promise', ['revision' => 0] + $this->terms(9001))->assertUnprocessable();
        $this->postJson($this->url.'/promise', ['revision' => 0, 'currency' => 'USD'] + $this->terms())->assertUnprocessable();
        $this->postJson($this->url.'/promise', ['revision' => 0] + $this->terms())->assertOk();
        $id = $this->getJson($this->url)->assertOk()->assertJsonPath('data.promise.covered_cents', '0')->json('data.promise.id');
        $this->postJson($this->url.'/promise', ['revision' => 0] + $this->terms())->assertConflict();
        $this->postJson($this->url.'/promise', ['revision' => 1] + $this->terms())->assertConflict();
        $this->postJson($this->url.'/fulfill', ['revision' => 1, 'promise_id' => $id])->assertConflict();
        $first = $this->pay(2000);
        $this->getJson($this->url)->assertJsonPath('data.promise.remaining_cents', '4000')->assertJsonPath('data.promise.overdue', true);
        $this->postJson($this->url.'/reschedule', ['revision' => 1, 'promise_id' => $id, 'reason' => 'Nuevo acuerdo'] + $this->terms(3000))->assertOk();
        $this->getJson($this->url)->assertJsonPath('data.promise.amount_cents', '5000')->assertJsonPath('data.promise.covered_cents', '2000');
        $this->pay(3000);
        $this->postJson($this->url.'/fulfill', ['revision' => 2, 'promise_id' => $id])->assertOk();
        $this->getJson($this->url)->assertJsonPath('data.promise', null)->assertJsonPath('data.history.0.evidence_review', false);
        $this->postJson($this->url.'/promise', ['revision' => 3] + $this->terms(1000))->assertOk();
        $this->getJson($this->url)->assertJsonPath('data.promise.covered_cents', '0');
        DB::table('payments')->where('id', $first)->update(['status' => 'charged_back', 'needs_review' => true]);
        $this->getJson($this->url)->assertJsonPath('data.history.1.evidence_review', true)
            ->assertJsonPath('data.history.1.confirmed_cents', '3000')->assertJsonPath('data.promise.review', true);
        Http::assertNothingSent();
    }

    public function test_credit_settles_invoice_without_fulfilling_agreement(): void
    {
        $this->postJson($this->url.'/promise', ['revision' => 0] + $this->terms())->assertOk();
        $note = Document::factory()->create(['company_id' => $this->doc->company_id, 'created_by' => $this->owner->id,
            'series_id' => $this->doc->series_id, 'doc_type' => '07', 'source_document_id' => $this->doc->id, 'credit_reason' => 'Ajuste']);
        DB::table('credit_note_lines')->insert(['company_id' => $this->doc->company_id, 'note_id' => $note->id,
            'source_document_id' => $this->doc->id, 'source_item_id' => $this->doc->items()->first()->id,
            'note_item_id' => $note->items()->first()->id, 'state' => 'applied']);
        $id = $this->getJson($this->url)->assertOk()->assertJsonPath('data.outstanding_cents', '0')
            ->assertJsonPath('data.promise.covered_cents', '0')->assertJsonPath('data.promise.review', true)->json('data.promise.id');
        $this->getJson('/api/v1/receivables/agenda?scope=team')->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson($this->url.'/fulfill', ['revision' => 1, 'promise_id' => $id])->assertConflict();
        $this->postJson($this->url.'/cancel', ['revision' => 1, 'promise_id' => $id, 'reason' => 'Saldo cubierto por crédito'])->assertOk();
        $this->getJson($this->url)->assertJsonPath('data.history.0.kind', 'cancel')->assertJsonPath('data.promise', null);
        $this->postJson($this->url.'/assign', ['revision' => 2, 'assignee_id' => $this->owner->id])->assertOk();
        $this->getJson('/api/v1/receivables/agenda?scope=mine')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_assignments_corrections_permissions_and_agenda_counts(): void
    {
        $issuer = User::factory()->create(['company_id' => $this->doc->company_id, 'role' => 'issuer']);
        $accountant = User::factory()->create(['company_id' => $this->doc->company_id, 'role' => 'accountant']);
        $foreign = User::factory()->create(['role' => 'issuer']);
        $this->postJson($this->url.'/assign', ['revision' => 0, 'assignee_id' => $foreign->id])->assertNotFound();
        $this->postJson($this->url.'/assign', ['revision' => 0, 'assignee_id' => $accountant->id])->assertNotFound();
        $this->postJson($this->url.'/assign', ['revision' => 0, 'assignee_id' => $issuer->id])->assertOk();
        $today = now('America/Lima')->toDateString();
        $contact = ['channel' => 'phone', 'result' => 'contacted', 'note' => 'Observación privada', 'next_action_on' => $today];
        $this->actingAs($accountant)->getJson($this->url)->assertOk()->assertJsonPath('data.can_manage', false);
        $this->postJson($this->url.'/contact', ['revision' => 1] + $contact)->assertForbidden();
        $this->actingAs($issuer)->getJson('/api/v1/receivables/agenda?scope=team')->assertForbidden();
        $this->postJson($this->url.'/contact', ['revision' => 1] + $contact)->assertOk();
        $id = $this->getJson($this->url)->assertOk()->json('data.last_contact.id');
        $this->postJson($this->url.'/correct', ['revision' => 2, 'contact_id' => $id, 'reason' => 'Aclaración', 'note' => 'Corregida'] + $contact)->assertOk();
        $this->getJson($this->url)->assertJsonPath('data.history.1.details.note', 'Observación privada')
            ->assertJsonPath('data.last_contact.details.note', 'Corregida');
        $this->getJson('/api/v1/receivables/agenda?scope=mine&task=action_today&from='.$today.'&to='.$today)
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.counts.action_today', 1)->assertDontSee('Corregida');
        $issuer->update(['is_active' => false]);
        $this->actingAs($this->owner)->getJson('/api/v1/receivables/agenda?scope=team&task=unassigned')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.assignee', $issuer->name);
        $this->postJson($this->url.'/assign', ['revision' => 3, 'assignee_id' => $this->owner->id])->assertOk();
        $this->actingAs($foreign)->getJson($this->url)->assertNotFound();
        $this->getJson('/api/v1/receivables/agenda?scope=mine')->assertJsonPath('data.total', 0);
    }
}
