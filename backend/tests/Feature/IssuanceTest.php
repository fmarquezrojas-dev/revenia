<?php

namespace Tests\Feature;

use App\Actions\Issuance\EmissionOutbox;
use App\Actions\Issuance\ProcessEmission;
use App\Actions\Issuance\RequestIssuance;
use App\Contracts\TaxProvider;
use App\Jobs\SendSimulatedDocument;
use App\Models\Document;
use App\Models\User;
use App\Services\SimulatedTaxProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\DatabaseTestCase;

class IssuanceTest extends DatabaseTestCase
{
    private function fixture(string $role = 'issuer'): array
    {
        $actor = User::factory()->create(['role' => $role]);
        $doc = Document::factory()->create(['company_id' => $actor->company_id, 'created_by' => $actor->id]);
        $this->actingAs($actor, 'web');

        return [$actor, $doc];
    }

    private function issue(Document $doc, ?string $key = null, int $version = 1): TestResponse
    {
        return $this->postJson('/api/v1/documents/'.$doc->id.'/issue', ['version' => $version], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    #[TestWith(['owner'])] #[TestWith(['issuer'])]
    public function test_issue_registers_atomic_number_snapshot_events_and_outbox(string $role): void
    {
        [$actor,$doc] = $this->fixture($role);
        Queue::fake();
        $response = $this->issue($doc)->assertAccepted()->assertJsonPath('data.number', 1)->assertJsonPath('data.status', 'queued')->assertJsonPath('data.version', 2);
        $this->assertSame(1, $doc->series->fresh()->current_number);
        $this->assertDatabaseCount('emission_outbox', 1);
        $this->assertDatabaseCount('emission_requests', 1);
        $this->assertDatabaseCount('document_events', 2);
        Queue::assertNothingPushed();
        $this->assertSame($actor->company->ruc, $doc->fresh()->fiscal_snapshot['issuer']['ruc']);
        $this->getJson($response->json('data.status_url'))->assertOk()->assertJsonPath('data.formatted_number', $doc->series->code.'-00000001');
    }

    public function test_replays_lost_response_after_processing_and_rejects_key_reuse(): void
    {
        [, $doc] = $this->fixture();
        $key = (string) Str::uuid();
        $first = $this->issue($doc, $key)->assertAccepted()->json();
        app(ProcessEmission::class)->handle($doc->company_id, $doc->id, 2);
        $this->issue($doc, $key)->assertAccepted()->assertExactJson($first);
        $this->issue($doc, $key, 2)->assertConflict();
        $this->issue($doc)->assertConflict();
        $other = Document::factory()->create(['company_id' => $doc->company_id, 'created_by' => $doc->created_by]);
        $this->issue($other, $key)->assertConflict();
        $this->assertDatabaseCount('emission_requests', 1);
        $this->assertDatabaseCount('emission_attempts', 1);
        $this->getJson('/api/v1/documents/'.$doc->id.'/events')->assertOk()->assertJsonPath('data.0.to_status', 'accepted');
        $this->assertSame('accepted', $doc->fresh()->status);
    }

    public function test_accountant_reads_but_cannot_issue_or_retry_and_foreign_ids_are_hidden(): void
    {
        [$actor,$doc] = $this->fixture('accountant');
        $this->issue($doc)->assertForbidden();
        $this->postJson('/api/v1/documents/'.$doc->id.'/retry', ['version' => 1], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->getJson('/api/v1/documents/'.$doc->id.'/events')->assertOk();
        $foreign = Document::factory()->create();
        $this->issue($foreign)->assertNotFound();
        $this->getJson('/api/v1/documents/'.$foreign->id.'/events')->assertNotFound();
        $this->assertDatabaseCount('emission_requests', 0);
    }

    #[TestWith(['user'])] #[TestWith(['company'])]
    public function test_inactive_identity_cannot_issue(string $target): void
    {
        [$actor,$doc] = $this->fixture();
        ($target === 'user' ? $actor : $actor->company)->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($actor->fresh(), 'web');
        $this->issue($doc)->assertUnauthorized();
        $this->assertNull($doc->fresh()->number);
    }

    #[TestWith(['missing_key'])] #[TestWith(['short_key'])] #[TestWith(['payload'])]
    public function test_rejects_invalid_request_without_effects(string $case): void
    {
        [, $doc] = $this->fixture();
        $payload = ['version' => 1];
        if ($case === 'payload') {
            $payload['scenario'] = 'accepted';
        }
        $headers = $case === 'missing_key' ? [] : ['Idempotency-Key' => $case === 'short_key' ? 'short' : (string) Str::uuid()];
        $this->postJson('/api/v1/documents/'.$doc->id.'/issue', $payload, $headers)->assertUnprocessable();
        $this->assertSame(0, $doc->series->fresh()->current_number);
    }

    #[TestWith(['series'])] #[TestWith(['product'])] #[TestWith(['empty'])] #[TestWith(['future'])] #[TestWith(['limit'])]
    public function test_final_validation_rolls_back_all_effects(string $case): void
    {
        [, $doc] = $this->fixture();
        match ($case) {
            'series' => $doc->series->update(['is_active' => false]),'product' => $doc->items->first()->product->update(['is_active' => false]),'empty' => $doc->items()->delete(),'future' => $doc->update(['issue_date' => now('America/Lima')->addDay()->toDateString()]),'limit' => DB::table('document_series')->where('id', $doc->series_id)->update(['current_number' => 99999999])
        };
        $before = $doc->series->fresh()->current_number;
        $this->issue($doc)->assertUnprocessable();
        $this->assertSame($before, $doc->series->fresh()->current_number);
        $this->assertNull($doc->fresh()->number);
        $this->assertDatabaseCount('emission_requests', 0);
        $this->assertDatabaseCount('emission_outbox', 0);
        $this->assertDatabaseCount('document_events', 0);
    }

    public function test_stale_version_returns_conflict_without_number(): void
    {
        [, $doc] = $this->fixture();
        $this->issue($doc, null, 2)->assertConflict();
        $this->assertNull($doc->fresh()->number);
    }

    public function test_transaction_failure_after_number_rolls_back(): void
    {
        [$actor,$doc] = $this->fixture();
        DB::beginTransaction();
        app(RequestIssuance::class)->handle($actor, $doc->id, 1, (string) Str::uuid());
        DB::rollBack();
        $this->assertNull($doc->fresh()->number);
        $this->assertSame(0, $doc->series->fresh()->current_number);
        $this->assertDatabaseCount('emission_requests', 0);
        $this->assertDatabaseCount('document_events', 0);
        $this->assertDatabaseCount('emission_outbox', 0);
    }

    #[TestWith(['accepted', 'accepted', null])] #[TestWith(['rejected', 'rejected', null])] #[TestWith(['temporary', 'failed', 'recoverable'])] #[TestWith(['timeout_before', 'failed', 'unknown'])] #[TestWith(['timeout_after', 'failed', 'unknown'])] #[TestWith(['permanent', 'failed', 'permanent'])]
    public function test_deterministic_provider_results(string $scenario, string $status, ?string $kind): void
    {
        [, $doc] = $this->fixture();
        config(['issuance.demo_controls' => true]);
        DB::table('simulation_scenarios')->insert(['document_id' => $doc->id, 'scenario' => $scenario]);
        $this->issue($doc)->assertAccepted();
        app(ProcessEmission::class)->handle($doc->company_id, $doc->id, 2);
        app(ProcessEmission::class)->handle($doc->company_id, $doc->id, 2);
        $this->assertSame($status, $doc->fresh()->status);
        $this->assertSame($kind, $doc->fresh()->failure_kind);
        $this->assertDatabaseCount('emission_attempts', 1);
        $this->assertDatabaseCount('simulated_submissions', 1);
        $this->assertSame($status === 'failed' && $kind !== 'permanent', $doc->fresh()->can_retry);
    }

    #[TestWith(['temporary', 2])] #[TestWith(['timeout_before', 2])] #[TestWith(['timeout_after', 1])]
    public function test_recovery_reconciles_same_identity_without_new_number(string $scenario, int $calls): void
    {
        $this->freezeTime();
        [, $doc] = $this->fixture();
        config(['issuance.demo_controls' => true]);
        DB::table('simulation_scenarios')->insert(['document_id' => $doc->id, 'scenario' => $scenario]);
        $this->issue($doc)->assertAccepted();
        $identity = $doc->fresh()->submission_id;
        app(ProcessEmission::class)->handle($doc->company_id, $doc->id, 2);
        $this->travel(31)->seconds();
        app(EmissionOutbox::class)->recover();
        $doc->refresh();
        app(ProcessEmission::class)->handle($doc->company_id, $doc->id, $doc->version);
        $this->assertSame('accepted', $doc->fresh()->status);
        $this->assertSame($identity, $doc->fresh()->submission_id);
        $this->assertSame(1, $doc->fresh()->number);
        $this->assertSame(1, $doc->series->fresh()->current_number);
        $this->assertSame($calls, DB::table('simulated_submissions')->value('calls'));
    }

    public function test_abandoned_attempt_and_late_result_cannot_overwrite_new_attempt(): void
    {
        $this->freezeTime();
        [, $doc] = $this->fixture();
        $this->issue($doc)->assertAccepted();
        $worker = app(ProcessEmission::class);
        $old = $worker->claim($doc->company_id, $doc->id, 2);
        $this->travel(91)->seconds();
        app(EmissionOutbox::class)->recover();
        $this->travel(31)->seconds();
        app(EmissionOutbox::class)->recover();
        $doc->refresh();
        $worker->handle($doc->company_id, $doc->id, $doc->version);
        $this->assertFalse($worker->complete($doc->company_id, $doc->id, $old->active_attempt_id, ['status' => 'rejected', 'code' => 'late', 'message' => 'Late']));
        $this->assertSame('accepted', $doc->fresh()->status);
        $this->assertDatabaseCount('emission_attempts', 2);
    }

    public function test_manual_retry_is_idempotent_and_final_states_cannot_retry(): void
    {
        [, $doc] = $this->fixture();
        config(['issuance.demo_controls' => true]);
        DB::table('simulation_scenarios')->insert(['document_id' => $doc->id, 'scenario' => 'timeout_after']);
        $this->issue($doc)->assertAccepted();
        app(ProcessEmission::class)->handle($doc->company_id, $doc->id, 2);
        $version = $doc->fresh()->version;
        $key = (string) Str::uuid();
        $url = '/api/v1/documents/'.$doc->id.'/retry';
        $response = $this->postJson($url, ['version' => $version], ['Idempotency-Key' => $key])->assertAccepted()->json();
        $this->postJson($url, ['version' => $version], ['Idempotency-Key' => $key])->assertAccepted()->assertExactJson($response);
        $this->assertDatabaseCount('emission_outbox', 2);
        $doc->refresh();
        app(ProcessEmission::class)->handle($doc->company_id, $doc->id, $doc->version);
        $this->postJson($url, ['version' => $doc->fresh()->version], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertSame(1, $doc->series->fresh()->current_number);
    }

    public function test_outbox_survives_queue_failure_and_duplicate_delivery(): void
    {
        $this->freezeTime();
        [, $doc] = $this->fixture();
        $this->issue($doc)->assertAccepted();
        Queue::shouldReceive('connection')->once()->andThrow(new \RuntimeException('secret connection data'));
        $this->assertSame(0, app(EmissionOutbox::class)->publish(1));
        $this->assertDatabaseHas('emission_outbox', ['published_at' => null, 'last_error' => 'queue_unavailable']);
        $this->assertSame('queued', $doc->fresh()->status);
        Queue::swap(app('queue'));
        Queue::fake([SendSimulatedDocument::class]);
        $this->travel(11)->seconds();
        $this->assertSame(1, app(EmissionOutbox::class)->publish());
        Queue::assertPushed(SendSimulatedDocument::class, 1);
        $this->travel(181)->seconds();
        app(EmissionOutbox::class)->recover();
        $this->assertSame(1, app(EmissionOutbox::class)->publish());
        Queue::assertPushed(SendSimulatedDocument::class, 2);
        app(ProcessEmission::class)->handle($doc->company_id, $doc->id, 2);
        app(ProcessEmission::class)->handle($doc->company_id, $doc->id, 2);
        $this->assertSame('accepted', $doc->fresh()->status);
        $this->assertDatabaseCount('emission_attempts', 1);
    }

    public function test_numbered_document_and_lines_are_immutable(): void
    {
        [, $doc] = $this->fixture();
        $this->issue($doc)->assertAccepted();
        $this->deleteJson('/api/v1/documents/'.$doc->id, ['version' => 2])->assertConflict();
        foreach ([fn () => $doc->fresh()->update(['currency' => 'USD']), fn () => $doc->items()->delete(), fn () => $doc->fresh()->delete()] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Expected immutable document');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->getCode());
            }
        }
        $this->assertSame(1, $doc->fresh()->number);
        $this->assertDatabaseCount('document_items', 1);
    }

    public function test_catalog_changes_do_not_change_captured_price_or_frozen_issuer(): void
    {
        [$actor,$doc] = $this->fixture();
        $line = $doc->items->first();
        $line->update(['unit_price_cents' => 1000]);
        $line->product->update(['unit_price_cents' => 9000]);
        $name = $actor->company->legal_name;
        $this->issue($doc)->assertAccepted();
        $actor->company->update(['legal_name' => 'Renamed company']);
        $line->product->update(['description' => 'Changed', 'is_active' => false]);
        $this->getJson('/api/v1/documents/'.$doc->id)->assertOk()->assertJsonPath('data.issuer.legal_name', $name)->assertJsonPath('data.items.0.unit_price_cents', 1000)->assertJsonPath('data.totals.total_cents', 1180);
    }

    public function test_retries_are_bounded_and_keep_one_identity(): void
    {
        $this->freezeTime();
        [$actor,$doc] = $this->fixture();
        $this->issue($doc)->assertAccepted();
        $identity = $doc->fresh()->submission_id;
        $provider = $this->mock(TaxProvider::class);
        $provider->shouldReceive('lookup')->times(5)->with($identity)->andReturn(null);
        $provider->shouldReceive('submit')->times(5)->with($identity, \Mockery::type('array'))->andReturn(SimulatedTaxProvider::failure('recoverable', 'temporary', 'Temporal'));
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $doc->refresh();
            app(ProcessEmission::class)->handle($doc->company_id, $doc->id, $doc->version);
            $doc->refresh();
            if ($attempt < 3) {
                $this->travel(31)->seconds();
                app(EmissionOutbox::class)->recover();
            } elseif ($attempt < 5) {
                $this->assertNull($doc->next_attempt_at);
                app(RequestIssuance::class)->handle($actor, $doc->id, $doc->version, (string) Str::uuid(), 'retry');
            }
        }
        $doc->refresh();
        $this->assertSame('failed', $doc->status);
        $this->assertFalse($doc->can_retry);
        $this->assertNull($doc->next_attempt_at);
        $this->assertSame(5, $doc->attempt_count);
        $this->assertSame(1, $doc->series->fresh()->current_number);
        $this->postJson('/api/v1/documents/'.$doc->id.'/retry', ['version' => $doc->version], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
    }

    public function test_simulator_deduplication_survives_new_instances_and_refuses_changed_payload(): void
    {
        [, $doc] = $this->fixture();
        $this->issue($doc)->assertAccepted();
        $id = $doc->fresh()->submission_id;
        $payload = ['company_id' => $doc->company_id, 'document_id' => $doc->id, 'total_cents' => 100];
        $first = (new SimulatedTaxProvider)->submit($id, $payload);
        $this->assertSame('accepted', $first['status']);
        ksort($first);
        foreach ([(new SimulatedTaxProvider)->lookup($id), (new SimulatedTaxProvider)->submit($id, $payload)] as $replayed) {
            ksort($replayed);
            $this->assertSame($first, $replayed);
        }
        $this->assertSame(1, DB::table('simulated_submissions')->value('calls'));
        $payload['total_cents'] = 200;
        $this->assertSame('identity_conflict', (new SimulatedTaxProvider)->submit($id, $payload)['code']);
    }

    public function test_demo_scenario_requires_opt_in_and_draft(): void
    {
        [, $doc] = $this->fixture();
        $this->artisan('issuance:scenario', ['document' => $doc->id, 'scenario' => 'rejected'])->assertFailed();
        $this->assertDatabaseCount('simulation_scenarios', 0);
        config(['issuance.demo_controls' => true]);
        $this->artisan('issuance:scenario', ['document' => $doc->id, 'scenario' => 'rejected'])->assertSuccessful();
        $this->issue($doc)->assertAccepted();
        $this->artisan('issuance:scenario', ['document' => $doc->id, 'scenario' => 'accepted'])->assertFailed();
    }

    public function test_unauthenticated_issue_is_rejected(): void
    {
        $doc = Document::factory()->create();
        $this->issue($doc)->assertUnauthorized();
        $this->assertNull($doc->fresh()->number);
    }
}
