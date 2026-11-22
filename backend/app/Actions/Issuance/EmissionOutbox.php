<?php

namespace App\Actions\Issuance;

use App\Jobs\DeliverDocumentEmail;
use App\Jobs\GeneratePrivatePdf;
use App\Jobs\SendSimulatedDocument;
use App\Models\Document;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

final class EmissionOutbox
{
    public function recover(): void
    {
        Document::where(function ($q): void {
            $q->whereIn('status', ['queued', 'sending'])->orWhere(fn ($q) => $q->where('status', 'failed')->whereNotNull('next_attempt_at'));
        })->select('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row): void {
                    $doc = Document::whereKey($row->id)->lockForUpdate()->firstOrFail();
                    if ($doc->status === 'sending' && $doc->lease_until?->lte(now())) {
                        $attempt = $doc->active_attempt_id;
                        $doc->status = 'failed';
                        $doc->version++;
                        $doc->active_attempt_id = null;
                        $doc->lease_until = null;
                        $doc->failure_kind = 'unknown';
                        $doc->result_code = 'lease_expired';
                        $doc->result_message = 'Intento interrumpido; pendiente de consulta al simulador.';
                        $doc->can_retry = $doc->attempt_count < 5;
                        $doc->next_attempt_at = $doc->attempt_count < 3 ? now()->addSeconds(30) : null;
                        $doc->save();
                        DB::table('emission_attempts')->where('id', $attempt)->update(['finished_at' => now(), 'outcome' => 'abandoned']);
                        RequestIssuance::event($doc, 'attempt_abandoned', 'sending', null, $attempt);
                    }
                    if ($doc->status === 'failed' && $doc->can_retry && $doc->next_attempt_at?->lte(now())) {
                        $doc->status = 'queued';
                        $doc->version++;
                        $doc->next_attempt_at = null;
                        $doc->result_code = null;
                        $doc->result_message = null;
                        $doc->failure_kind = null;
                        $doc->can_retry = false;
                        $doc->save();
                        RequestIssuance::event($doc, 'recovered', 'failed');
                        RequestIssuance::enqueue($doc);
                    }
                    if ($doc->status === 'queued') {
                        self::republish($doc, 'emission', $doc->version);
                    }
                });
            }
        });
    }

    /** Called inside the recovery transaction, with the document locked. */
    public static function republish(Document $doc, string $kind, int $generation): void
    {
        $row = DB::table('emission_outbox')->where('company_id', $doc->company_id)->where('document_id', $doc->id)
            ->where('kind', $kind)->where('generation', $generation)->where('published_at', '<', now()->subSeconds(180))->lockForUpdate()->first();
        if ($row) {
            self::queueEvent($row->id, $row->publication_attempts, 'publication_delayed',
                CarbonImmutable::parse($row->published_at)->addSeconds(180));
            DB::table('emission_outbox')->where('id', $row->id)->update(['published_at' => null, 'available_at' => now()]);
        }
    }

    private static function queueEvent(string $id, int $attempt, string $event, \DateTimeInterface $at): void
    {
        DB::table('incident_queue_events')->insertOrIgnore(['id' => (string) Str::uuid7(), 'publication_id' => $id,
            'attempt' => $attempt, 'event' => $event, 'occurred_at' => $at]);
    }

    public function publish(int $limit = 100): int
    {
        $count = 0;
        for ($i = 0; $i < $limit; $i++) {
            $row = DB::transaction(function (): ?object {
                $row = DB::table('emission_outbox')->whereNull('published_at')->where('available_at', '<=', now())->where(fn ($q) => $q->whereNull('claim_until')->orWhere('claim_until', '<=', now()))->orderBy('available_at')->lock('FOR UPDATE SKIP LOCKED')->first();
                if (! $row) {
                    return null;
                }$row->claim_token = (string) Str::uuid();
                DB::table('emission_outbox')->where('id', $row->id)->update(['claim_token' => $row->claim_token, 'claim_until' => now()->addSeconds(30), 'publication_attempts' => $row->publication_attempts + 1]);

                return $row;
            });
            if (! $row) {
                break;
            }
            try {
                $job = match ($row->kind) {
                    'pdf' => new GeneratePrivatePdf($row->company_id, $row->document_id, $row->generation),
                    'email' => new DeliverDocumentEmail($row->company_id, $row->document_id, $row->target_id),
                    default => new SendSimulatedDocument($row->company_id, $row->document_id, $row->generation),
                };
                Queue::connection(config($row->kind === 'emission' ? 'issuance.queue_connection' : 'delivery.queue_connection'))->push($job, '', $row->kind === 'emission' ? 'emissions' : 'documents');
                DB::table('emission_outbox')->where('id', $row->id)->where('claim_token', $row->claim_token)->update(['published_at' => now(), 'claim_until' => null, 'claim_token' => null, 'last_error' => null]);
                $count++;
            } catch (\Throwable) {
                DB::transaction(function () use ($row): void {
                    $attempt = $row->publication_attempts + 1;
                    $changed = DB::table('emission_outbox')->where('id', $row->id)->where('claim_token', $row->claim_token)->update([
                        'claim_until' => null, 'claim_token' => null, 'available_at' => now()->addSeconds(min(300, 10 * $attempt)),
                        'last_error' => 'queue_unavailable',
                    ]);
                    if ($changed) {
                        self::queueEvent($row->id, $attempt, 'publication_failed', now());
                    }
                });
            }
        }

        return $count;
    }
}
