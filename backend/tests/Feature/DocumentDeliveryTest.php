<?php

namespace Tests\Feature;

use App\Actions\Delivery\DocumentDelivery;
use App\Actions\Delivery\GenerateDocumentPdf;
use App\Actions\Delivery\SendDocumentEmail;
use App\Actions\Issuance\EmissionOutbox;
use App\Actions\Issuance\ProcessEmission;
use App\Actions\Issuance\RequestIssuance;
use App\Jobs\GeneratePrivatePdf;
use App\Mail\AcceptedDocumentMail;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentPdfRenderer;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\DatabaseTestCase;

class DocumentDeliveryTest extends DatabaseTestCase
{
    private function fixture(string $role = 'owner', bool $email = true): array
    {
        Storage::fake('documents');
        $actor = User::factory()->create(['role' => 'owner']);
        $customer = Customer::factory()->for($actor->company)->create(['email' => $email ? 'captured@example.test' : null]);
        $doc = Document::factory()->create(['company_id' => $actor->company_id, 'created_by' => $actor->id, 'customer_id' => $customer->id]);
        $doc->items()->first()->update(['unit_price_cents' => 1000]);
        app(RequestIssuance::class)->handle($actor, $doc->id, 1, (string) Str::uuid());
        app(ProcessEmission::class)->handle($doc->company_id, $doc->id, 2);
        $actor->update(['role' => $role]);
        $this->actingAs($actor->fresh(), 'web');

        return [$actor->fresh(), $doc->fresh(), $customer];
    }

    private function generate(Document $doc): object
    {
        app(GenerateDocumentPdf::class)->handle($doc->company_id, $doc->id, DB::table('document_pdfs')->where('document_id', $doc->id)->value('version'));

        return DB::table('document_pdfs')->where('document_id', $doc->id)->first();
    }

    private function email(Document $doc, ?string $key = null, ?string $file = null): TestResponse
    {
        return $this->postJson('/api/v1/documents/'.$doc->id.'/email', ['file_id' => $file ?? DB::table('document_pdfs')->where('document_id', $doc->id)->value('current_file_id')], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function regenerate(Document $doc, int $version, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/documents/'.$doc->id.'/pdf/regenerate', ['version' => $version], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_acceptance_schedules_pdf_and_real_renderer_publishes_private_file(): void
    {
        [, $doc] = $this->fixture();
        $this->assertDatabaseHas('emission_outbox', ['document_id' => $doc->id, 'kind' => 'pdf', 'generation' => 1]);
        $pdf = $this->generate($doc);
        $this->assertSame('ready', $pdf->status);
        $file = DocumentDelivery::file($pdf->current_file_id, $doc->company_id, $doc->id);
        $this->assertTrue(DocumentDelivery::validFile($file));
        $this->assertStringStartsWith('%PDF-', Storage::disk('documents')->get($file->path));
        $this->assertSame('accepted', $doc->fresh()->status);
        $this->assertSame(1, $doc->fresh()->number);
        $response = $this->get('/api/v1/documents/'.$doc->id.'/pdf');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
        $this->assertCount(1, Storage::disk('documents')->allFiles());
    }

    #[TestWith(['owner', true, true])] #[TestWith(['issuer', true, false])] #[TestWith(['accountant', false, false])]
    public function test_permissions_and_recipient_visibility(string $role, bool $send, bool $regenerate): void
    {
        [, $doc] = $this->fixture($role);
        $this->generate($doc);
        $this->get('/api/v1/documents/'.$doc->id.'/pdf')->assertOk();
        $detail = $this->getJson('/api/v1/documents/'.$doc->id)->assertOk()->assertJsonPath('data.delivery.email.recipient', $send ? 'captured@example.test' : null);
        $this->assertArrayNotHasKey('email', $detail->json('data.customer'));
        $this->email($doc)->assertStatus($send ? 202 : 403);
        $this->regenerate($doc, 1)->assertStatus($regenerate ? 202 : 403);
    }

    public function test_cross_tenant_and_inactive_accounts_cannot_access_private_operations(): void
    {
        [$actor,$doc] = $this->fixture();
        $this->generate($doc);
        $foreign = User::factory()->create(['role' => 'owner']);
        $this->actingAs($foreign, 'web');
        $this->getJson('/api/v1/documents/'.$doc->id.'/pdf')->assertNotFound();
        $this->email($doc)->assertNotFound();
        $this->regenerate($doc, 1)->assertNotFound();
        $actor->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($actor->fresh(), 'web');
        $this->getJson('/api/v1/documents/'.$doc->id.'/pdf')->assertUnauthorized();
    }

    public function test_drafts_cannot_generate_download_or_send(): void
    {
        $actor = User::factory()->create(['role' => 'owner']);
        $doc = Document::factory()->create(['company_id' => $actor->company_id, 'created_by' => $actor->id]);
        $this->actingAs($actor, 'web');
        $this->getJson('/api/v1/documents/'.$doc->id.'/pdf')->assertConflict()->assertJsonPath('code', 'document_not_accepted');
        $this->regenerate($doc, 0)->assertConflict();
        $this->email($doc, null, (string) Str::uuid())->assertConflict();
        $this->assertDatabaseCount('document_pdfs', 0);
    }

    public function test_missing_pdf_is_distinct_from_pending_and_failed(): void
    {
        [, $doc] = $this->fixture();
        $this->getJson('/api/v1/documents/'.$doc->id.'/pdf')->assertConflict()->assertJsonPath('code', 'pdf_pending');
        $pdf = $this->generate($doc);
        $file = DocumentDelivery::file($pdf->current_file_id, $doc->company_id, $doc->id);
        Storage::disk('documents')->delete($file->path);
        $this->getJson('/api/v1/documents/'.$doc->id.'/pdf')->assertNotFound()->assertJsonPath('code', 'pdf_missing');
        $this->email($doc)->assertConflict()->assertJsonPath('code', 'pdf_unavailable');
        $this->assertSame('accepted', $doc->fresh()->status);
    }

    public function test_duplicate_jobs_do_not_render_twice_and_catalog_changes_are_irrelevant(): void
    {
        [$actor,$doc,$customer] = $this->fixture();
        $name = $doc->fiscal_snapshot['issuer']['legal_name'];
        $actor->company->update(['legal_name' => 'CURRENT COMPANY']);
        $customer->update(['name' => 'CURRENT CUSTOMER']);
        $doc->items->first()->product->update(['description' => 'CURRENT PRODUCT', 'unit_price_cents' => 99999]);
        $html = view('documents.pdf', ['doc' => $doc->load('items'), 'snapshot' => $doc->fiscal_snapshot])->render();
        $this->assertStringContainsString(e($name), $html);
        $this->assertStringNotContainsString('CURRENT COMPANY', $html);
        $this->assertStringNotContainsString('CURRENT CUSTOMER', $html);
        $this->assertStringNotContainsString('CURRENT PRODUCT', $html);
        $this->assertStringContainsString('11.80 PEN', $html);
        $first = $this->generate($doc);
        $second = $this->generate($doc);
        $this->assertSame($first->current_file_id, $second->current_file_id);
        $this->assertDatabaseCount('document_pdf_files', 1);
        $this->assertDatabaseCount('document_delivery_attempts', 1);
    }

    public function test_generation_failure_and_failed_regeneration_preserve_acceptance_and_old_file(): void
    {
        [, $doc] = $this->fixture();
        $first = $this->generate($doc);
        $key = (string) Str::uuid();
        $this->regenerate($doc, 1, $key)->assertAccepted();
        $this->regenerate($doc, 1, $key)->assertAccepted();
        $this->regenerate($doc, 1)->assertConflict();
        $this->mock(DocumentPdfRenderer::class)->shouldReceive('render')->once()->andThrow(new \RuntimeException('private path and credentials'));
        $failed = $this->generate($doc);
        $this->assertSame('failed', $failed->status);
        $this->assertSame('pdf_generation_failed', $failed->error_code);
        $this->assertSame($first->current_file_id, $failed->current_file_id);
        $this->get('/api/v1/documents/'.$doc->id.'/pdf')->assertOk();
        $this->assertSame('accepted', $doc->fresh()->status);
        $this->assertCount(1, Storage::disk('documents')->allFiles());
    }

    public function test_incomplete_snapshot_fails_without_inventing_current_data(): void
    {
        [, $doc] = $this->fixture();
        $this->mock(DocumentPdfRenderer::class)->shouldReceive('render')->once()->andThrow(new \DomainException('snapshot_incomplete'));
        $pdf = $this->generate($doc);
        $this->assertSame('failed', $pdf->status);
        $this->assertNull($pdf->next_attempt_at);
        $this->getJson('/api/v1/documents/'.$doc->id.'/pdf')->assertConflict()->assertJsonPath('code', 'pdf_failed');
        $this->assertSame('accepted', $doc->fresh()->status);
        $copy = $doc->replicate();
        $copy->fiscal_snapshot = ['issuer' => []];
        $this->expectException(\DomainException::class);
        (new DocumentPdfRenderer)->render($copy);
    }

    public function test_recovery_requeues_abandoned_pdf_and_ignores_obsolete_job(): void
    {
        $this->freezeTime();
        [, $doc] = $this->fixture();
        $id = (string) Str::uuid();
        $pdf = DB::table('document_pdfs')->first();
        DB::table('document_pdfs')->where('id', $pdf->id)->update(['status' => 'generating', 'attempts' => 1, 'active_attempt_id' => $id, 'lease_until' => now()->subSecond()]);
        app(DocumentDelivery::class)->recover();
        $this->assertDatabaseHas('document_pdfs', ['status' => 'failed', 'error_code' => 'lease_expired']);
        $this->travel(31)->seconds();
        app(DocumentDelivery::class)->recover();
        app(GenerateDocumentPdf::class)->handle($doc->company_id, $doc->id, 1);
        $this->assertDatabaseCount('document_pdf_files', 0);
        $pdf = $this->generate($doc);
        $this->assertSame('ready', $pdf->status);
        $this->assertSame(2, $pdf->attempts);
    }

    public function test_outbox_recovers_unpublished_and_lost_pdf_jobs(): void
    {
        $this->freezeTime();
        [, $doc] = $this->fixture();
        DB::table('emission_outbox')->where('kind', 'emission')->update(['published_at' => now()]);
        Queue::shouldReceive('connection')->once()->andThrow(new \RuntimeException('Redis down'));
        $this->assertSame(0, app(EmissionOutbox::class)->publish(1));
        $this->assertDatabaseHas('emission_outbox', ['kind' => 'pdf', 'published_at' => null]);
        Queue::fake();
        $this->travel(11)->seconds();
        $this->assertSame(1, app(EmissionOutbox::class)->publish());
        Queue::assertPushed(GeneratePrivatePdf::class, 1);
        $this->travel(181)->seconds();
        app(DocumentDelivery::class)->recover();
        $this->assertSame(1, app(EmissionOutbox::class)->publish());
        Queue::assertPushed(GeneratePrivatePdf::class, 2);
    }

    public function test_email_captures_recipient_file_and_idempotent_intent(): void
    {
        [, $doc,$customer] = $this->fixture();
        $pdf = $this->generate($doc);
        $customer->update(['email' => 'changed@example.test']);
        $key = (string) Str::uuid();
        $response = $this->email($doc, $key)->assertAccepted()->json();
        $this->email($doc, $key)->assertAccepted()->assertExactJson($response);
        $this->email($doc, $key, (string) Str::uuid())->assertConflict();
        $this->email($doc)->assertConflict();
        $this->assertDatabaseCount('document_email_deliveries', 1);
        $this->assertDatabaseHas('document_email_deliveries', ['recipient' => 'captured@example.test', 'file_id' => $pdf->current_file_id, 'status' => 'pending']);
        Mail::fake();
        $id = $response['data']['id'];
        app(SendDocumentEmail::class)->handle($doc->company_id, $doc->id, $id);
        app(SendDocumentEmail::class)->handle($doc->company_id, $doc->id, $id);
        Mail::assertSent(AcceptedDocumentMail::class, fn ($mail) => $mail->hasTo('captured@example.test') && $mail->file->id === $pdf->current_file_id && $mail->headers()->messageId === $id.'@facturly.test');
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('document_email_deliveries', ['status' => 'sent']);
        $this->assertSame('accepted', $doc->fresh()->status);
    }

    public function test_email_requires_snapshot_recipient_and_ready_pdf(): void
    {
        [, $doc] = $this->fixture('owner', false);
        $this->email($doc, null, (string) Str::uuid())->assertConflict();
        $this->generate($doc);
        $this->email($doc)->assertUnprocessable()->assertJsonPath('code', 'recipient_unavailable');
        $this->assertDatabaseCount('document_email_deliveries', 0);
    }

    public function test_email_rejects_arbitrary_payload_and_rate_limits_intentional_resend(): void
    {
        $this->freezeTime();
        [, $doc] = $this->fixture();
        $pdf = $this->generate($doc);
        $url = '/api/v1/documents/'.$doc->id.'/email';
        $this->postJson($url, ['file_id' => $pdf->current_file_id, 'recipient' => 'arbitrary@example.test'], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        Mail::fake();
        for ($i = 0; $i < 3; $i++) {
            $id = $this->email($doc)->assertAccepted()->json('data.id');
            app(SendDocumentEmail::class)->handle($doc->company_id, $doc->id, $id);
            $this->email($doc)->assertTooManyRequests();
            $this->travel(61)->seconds();
        }
        $this->email($doc)->assertTooManyRequests();
        Mail::assertSentCount(3);
    }

    public function test_transport_error_is_unknown_and_never_automatically_retried(): void
    {
        [, $doc] = $this->fixture();
        $this->generate($doc);
        $id = $this->email($doc)->assertAccepted()->json('data.id');
        $transport = \Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->once()->andThrow(new TransportException('synthetic SMTP failure'));
        Mail::mailer()->setSymfonyTransport($transport);
        app(SendDocumentEmail::class)->handle($doc->company_id, $doc->id, $id);
        app(DocumentDelivery::class)->recover();
        app(SendDocumentEmail::class)->handle($doc->company_id, $doc->id, $id);
        $this->assertDatabaseHas('document_email_deliveries', ['id' => $id, 'status' => 'unknown', 'error_code' => 'delivery_unknown']);
        $this->email($doc)->assertConflict()->assertJsonPath('code', 'email_unknown');
        $this->assertSame('accepted', $doc->fresh()->status);
        $this->artisan('delivery:resolve', ['delivery' => $id, 'result' => 'sent'])->assertFailed();
        $this->artisan('delivery:resolve', ['delivery' => $id, 'result' => 'sent', '--confirmed' => true])->assertSuccessful();
        $this->assertDatabaseHas('document_email_deliveries', ['id' => $id, 'status' => 'sent']);
    }

    public function test_missing_attachment_fails_before_transport_and_regeneration_keeps_bound_file(): void
    {
        [, $doc] = $this->fixture();
        $pdf = $this->generate($doc);
        $id = $this->email($doc)->assertAccepted()->json('data.id');
        $this->regenerate($doc, 1)->assertAccepted();
        $this->generate($doc);
        $old = DocumentDelivery::file($pdf->current_file_id, $doc->company_id, $doc->id);
        $this->assertTrue(DocumentDelivery::validFile($old));
        Storage::disk('documents')->delete($old->path);
        Mail::fake();
        app(SendDocumentEmail::class)->handle($doc->company_id, $doc->id, $id);
        Mail::assertNothingSent();
        $this->assertDatabaseHas('document_email_deliveries', ['status' => 'failed', 'error_code' => 'pdf_unavailable']);
    }

    public function test_public_demo_cannot_send_to_arbitrary_recipient(): void
    {
        [, $doc] = $this->fixture();
        $this->generate($doc);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->app->instance('env', 'production');
        $this->email($doc)->assertUnprocessable();
        config(['delivery.demo_recipient' => 'controlled@example.test']);
        $id = $this->email($doc)->assertAccepted()->json('data.id');
        $this->assertDatabaseHas('document_email_deliveries', ['id' => $id, 'recipient' => 'controlled@example.test']);
        Mail::fake();
        app(SendDocumentEmail::class)->handle($doc->company_id, $doc->id, $id);
        Mail::assertNothingSent();
        $this->assertDatabaseHas('document_email_deliveries', ['id' => $id, 'status' => 'failed', 'error_code' => 'demo_transport_blocked']);
    }

    public function test_cleanup_only_removes_old_unreferenced_attempt_files(): void
    {
        [, $doc] = $this->fixture();
        $pdf = $this->generate($doc);
        $disk = Storage::disk('documents');
        $orphan = $doc->company_id.'/'.$doc->id.'/'.Str::uuid().'.tmp';
        $disk->put($orphan, 'partial');
        touch($disk->path($orphan), now()->subDays(2)->timestamp);
        $this->assertSame(1, app(DocumentDelivery::class)->cleanOrphans());
        $this->assertTrue(DocumentDelivery::validFile(DocumentDelivery::file($pdf->current_file_id, $doc->company_id, $doc->id)));
    }

    public function test_late_renderer_cannot_publish_after_lease_expires(): void
    {
        $this->freezeTime();
        [, $doc] = $this->fixture();
        $first = $this->generate($doc);
        $file = DocumentDelivery::file($first->current_file_id, $doc->company_id, $doc->id);
        $bytes = Storage::disk('documents')->get($file->path);
        $this->regenerate($doc, 1)->assertAccepted();
        $this->mock(DocumentPdfRenderer::class)->shouldReceive('render')->once()->andReturnUsing(function () use ($bytes): string {
            $this->travel(91)->seconds();

            return $bytes;
        });
        $this->generate($doc);
        $this->assertDatabaseCount('document_pdf_files', 1);
        $this->assertCount(1, Storage::disk('documents')->allFiles());
        app(DocumentDelivery::class)->recover();
        $this->assertDatabaseHas('document_pdfs', ['current_file_id' => $file->id, 'status' => 'failed']);
    }

    public function test_lost_sending_email_becomes_unknown_without_resend(): void
    {
        $this->freezeTime();
        [, $doc] = $this->fixture();
        $this->generate($doc);
        $id = $this->email($doc)->assertAccepted()->json('data.id');
        DB::table('document_email_deliveries')->where('id', $id)->update(['status' => 'sending', 'active_attempt_id' => (string) Str::uuid(), 'lease_until' => now()->subSecond()]);
        Mail::fake();
        app(DocumentDelivery::class)->recover();
        app(SendDocumentEmail::class)->handle($doc->company_id, $doc->id, $id);
        Mail::assertNothingSent();
        $this->assertDatabaseHas('document_email_deliveries', ['id' => $id, 'status' => 'unknown']);
    }

    public function test_template_escapes_commercial_html(): void
    {
        [, $doc] = $this->fixture();
        $doc->items->first()->description = '<script>alert("test")</script><img src="http://example.test/private">';
        $html = view('documents.pdf', ['doc' => $doc, 'snapshot' => $doc->fiscal_snapshot])->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_real_mailer_builds_pdf_attachment_and_both_bodies(): void
    {
        [, $doc] = $this->fixture();
        $pdf = $this->generate($doc);
        $file = DocumentDelivery::file($pdf->current_file_id, $doc->company_id, $doc->id);
        $mail = new AcceptedDocumentMail($doc, $file, (string) Str::uuid().'@facturly.test');
        view('documents.email', ['document' => $doc])->render();
        view('documents.email-text', ['document' => $doc])->render();
        $sent = Mail::to('captured@example.test')->send($mail);
        $this->assertNotNull($sent);
        $message = $sent->getOriginalMessage();
        $this->assertStringContainsString('Sin validez tributaria', $message->getTextBody());
        $this->assertStringContainsString('Sin validez tributaria', $message->getHtmlBody());
        $this->assertCount(1, $message->getAttachments());
    }
}
