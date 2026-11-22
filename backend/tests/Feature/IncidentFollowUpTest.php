<?php

namespace Tests\Feature;

use App\Actions\Issuance\ProcessEmission;
use App\Actions\Issuance\RequestIssuance;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

class IncidentFollowUpTest extends DatabaseTestCase
{
    public function test_review_is_idempotent_and_does_not_retry_or_resolve_an_incident(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'owner']);
        $doc = Document::factory()->create(['company_id' => $owner->company_id, 'created_by' => $owner->id]);
        config(['issuance.demo_controls' => true]);
        $this->artisan('issuance:scenario', ['document' => $doc->id, 'scenario' => 'permanent'])->assertSuccessful();
        app(RequestIssuance::class)->handle($owner, $doc->id, 1, (string) Str::uuid());
        app(ProcessEmission::class)->handle($owner->company_id, $doc->id, 2);
        $url = '/api/v1/incidents/emission/'.$doc->id;
        $this->actingAs($owner, 'web');
        $issue = $this->getJson($url)->assertOk()->assertJsonPath('data.state', 'failed')->assertJsonPath('data.action', null)->json('data');
        $body = ['action' => 'reviewed', 'revision' => $issue['revision']];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $before = DB::table('emission_outbox')->where('document_id', $doc->id)->count();
        foreach ([1, 2] as $attempt) {
            $this->postJson($url.'/actions', $body, $headers)->assertOk()->assertJsonPath('data.outcome', 'recorded');
        }
        $this->assertDatabaseCount('incident_follow_ups', 1);
        $this->getJson($url)->assertOk()->assertJsonPath('data.state', 'failed')->assertJsonFragment(['actor' => $owner->name, 'event' => 'reviewed']);
        $this->assertSame($before, DB::table('emission_outbox')->where('document_id', $doc->id)->count());
        $this->assertSame(1, $doc->fresh()->attempt_count);
        $this->getJson('/api/v1/incidents?q='.$doc->id)->assertJsonPath('data.total', 1);

        $accountant = User::factory()->create(['company_id' => $owner->company_id, 'role' => 'accountant']);
        // Each browser session resolves its own Sanctum guard.
        $this->app['auth']->forgetGuards();
        $this->actingAs($accountant, 'web');
        $this->getJson($url)->assertOk()->assertJsonPath('data.can_review', false);
        $this->postJson($url.'/actions', $body, $headers)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'web');
        $this->getJson($url)->assertNotFound();
        $this->getJson('/api/v1/incidents?q='.$doc->id)->assertJsonPath('data.total', 0);
        $this->postJson($url.'/actions', $body, $headers)->assertNotFound();
    }
}
