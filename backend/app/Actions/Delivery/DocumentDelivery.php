<?php

namespace App\Actions\Delivery;

use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DocumentDelivery
{
    public static function error(string $code, string $message, int $status = 409): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }

    public static function outbox(Document $doc, string $kind, string $target, int $version): void
    {
        DB::table('emission_outbox')->insert(['id' => (string) Str::uuid(), 'company_id' => $doc->company_id, 'document_id' => $doc->id, 'kind' => $kind, 'target_id' => $target, 'generation' => $version, 'available_at' => now(), 'created_at' => now()]);
    }

    /** Called with the accepted document locked, within its transaction. */
    public static function ensurePdf(Document $doc): void
    {
        if ($doc->status !== 'accepted' || DB::table('document_pdfs')->where('document_id', $doc->id)->exists()) {
            return;
        }
        $id = (string) Str::uuid();
        DB::table('document_pdfs')->insert(['id' => $id, 'company_id' => $doc->company_id, 'document_id' => $doc->id, 'status' => 'pending', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        self::outbox($doc, 'pdf', $id, 1);
    }

    public static function file(?string $id, string $company, string $document): ?object
    {
        return $id ? DB::table('document_pdf_files')->where(['id' => $id, 'company_id' => $company, 'document_id' => $document])->first() : null;
    }

    public static function validFile(?object $file): bool
    {
        if (! $file || $file->disk !== 'documents' || ! preg_match('/\A[a-f0-9-]{36}\/[a-f0-9-]{36}\/[a-f0-9-]{36}\.pdf\z/', $file->path)) {
            return false;
        }
        try {
            $disk = Storage::disk('documents');

            return $disk->exists($file->path) && $disk->size($file->path) === $file->size && hash_equals($file->sha256, hash_file('sha256', $disk->path($file->path)));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function recipient(Document $doc): ?string
    {
        $captured = $doc->fiscal_snapshot['customer']['email'] ?? null;
        if (! is_string($captured) || ! filter_var($captured, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        if (app()->environment(['local', 'testing'])) {
            return $captured;
        }
        $controlled = config('delivery.demo_recipient');

        return is_string($controlled) && filter_var($controlled, FILTER_VALIDATE_EMAIL) ? $controlled : null;
    }

    public static function summary(Document $doc, ?User $actor): ?array
    {
        if ($doc->status !== 'accepted') {
            return null;
        }
        $pdf = DB::table('document_pdfs')->where('company_id', $doc->company_id)->where('document_id', $doc->id)->first();
        $file = self::file($pdf?->current_file_id, $doc->company_id, $doc->id);
        $available = self::validFile($file);
        $canSend = $actor && Gate::forUser($actor)->allows('sendEmail', $doc);
        $owner = $actor && Gate::forUser($actor)->allows('regeneratePdf', $doc);
        $deliveries = DB::table('document_email_deliveries')->where('company_id', $doc->company_id)->where('document_id', $doc->id)->orderByDesc('sequence')->limit(20)->get();

        return ['pdf' => ['status' => $pdf?->status ?? 'pending', 'next_attempt_at' => $pdf?->next_attempt_at, 'version' => $pdf?->version ?? 0, 'available' => $available, 'file_id' => $available ? $file->id : null, 'generated_at' => $file?->created_at, 'error_code' => $pdf?->error_code ?? ($file && ! $available ? 'file_missing' : null), 'can_regenerate' => $owner && (! $pdf || ! in_array($pdf->status, ['pending', 'generating'])) && ($pdf?->attempts ?? 0) < 5],
            'email' => ['recipient' => $canSend ? self::recipient($doc) : null, 'can_send' => $canSend && $available && self::recipient($doc) !== null && ! $deliveries->contains(fn ($d) => in_array($d->status, ['pending', 'sending', 'unknown'])), 'deliveries' => $deliveries->map(fn ($row) => ['id' => $row->id, 'status' => $row->status, 'recipient' => $canSend ? $row->recipient : null, 'message_id' => $canSend ? $row->message_id : null, 'error_code' => $row->error_code, 'created_at' => $row->created_at, 'sent_at' => $row->sent_at])->all()]];
    }

    public function request(User $actor, string $id, string $operation, string $key, array $payload): array
    {
        return DB::transaction(function () use ($actor, $id, $operation, $key, $payload): array {
            $company = Company::whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($actor->id)->where('company_id', $company->id)->sharedLock()->firstOrFail();
            abort_unless($company->is_active && $actor->is_active, 401);
            $actor->setRelation('company', $company);
            $doc = Document::where('company_id', $company->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($operation === 'pdf' ? 'regeneratePdf' : 'sendEmail', $doc);
            ksort($payload);
            $fingerprint = hash('sha256', json_encode([$operation, $id, $payload], JSON_THROW_ON_ERROR));
            $old = DB::table('document_delivery_requests')->where(['company_id' => $company->id, 'operation' => $operation, 'key' => $key])->first();
            if ($old) {
                if (! hash_equals($old->fingerprint, $fingerprint)) {
                    self::error('idempotency_conflict', 'La clave pertenece a otra solicitud.');
                }

                return json_decode($old->response, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($doc->status !== 'accepted') {
                self::error('document_not_accepted', 'Solo los comprobantes aceptados permiten PDF y correo.');
            }
            $pdf = DB::table('document_pdfs')->where('document_id', $id)->lockForUpdate()->first();
            if ($operation === 'pdf') {
                if (! $pdf) {
                    self::ensurePdf($doc);
                    $pdf = DB::table('document_pdfs')->where('document_id', $id)->first();
                } else {
                    if ($pdf->version !== $payload['version']) {
                        self::error('pdf_version_conflict', 'El PDF cambio. Actualiza su estado.');
                    }
                    if (in_array($pdf->status, ['pending', 'generating'])) {
                        self::error('pdf_busy', 'Ya hay una generacion pendiente.');
                    }
                    if ($pdf->attempts >= 5) {
                        self::error('pdf_attempt_limit', 'Se alcanzo el limite de intentos. Requiere revision tecnica.');
                    }
                    $pdf->version++;
                    DB::table('document_pdfs')->where('id', $pdf->id)->update(['status' => 'pending', 'version' => $pdf->version, 'requested_by' => $actor->id, 'error_code' => null, 'next_attempt_at' => null, 'updated_at' => now()]);
                    self::outbox($doc, 'pdf', $pdf->id, $pdf->version);
                }
                $response = ['data' => ['id' => $pdf->id, 'status' => 'pending', 'version' => $pdf->version]];
            } else {
                $file = self::file($pdf?->current_file_id, $company->id, $id);
                if (! self::validFile($file)) {
                    self::error('pdf_unavailable', 'El PDF debe estar disponible antes de solicitar un correo.');
                }
                if ($file->id !== $payload['file_id']) {
                    self::error('pdf_version_conflict', 'El PDF cambio. Revisa la version disponible.');
                }
                $recipient = self::recipient($doc);
                if (! $recipient) {
                    self::error('recipient_unavailable', 'No hay correo capturado o destino de pruebas autorizado.', 422);
                }
                $q = DB::table('document_email_deliveries')->where('document_id', $id);
                if ((clone $q)->whereIn('status', ['pending', 'sending'])->exists()) {
                    self::error('email_busy', 'Ya hay un envio pendiente.');
                }
                if ((clone $q)->where('status', 'unknown')->exists()) {
                    self::error('email_unknown', 'Revisa el resultado incierto en el transporte antes de otro envio.');
                }
                if ((clone $q)->where('created_at', '>', now()->subSeconds(config('delivery.email_cooldown_seconds')))->exists() || (clone $q)->where('created_at', '>=', now()->subDay())->count() >= config('delivery.max_emails_per_day')) {
                    self::error('email_rate_limit', 'Espera antes de enviar nuevamente. Maximo tres solicitudes en 24 horas.', 429);
                }
                $delivery = (string) Str::uuid();
                $sequence = ((int) (clone $q)->max('sequence')) + 1;
                DB::table('document_email_deliveries')->insert(['id' => $delivery, 'company_id' => $company->id, 'document_id' => $id, 'file_id' => $file->id, 'requested_by' => $actor->id, 'recipient' => $recipient, 'status' => 'pending', 'sequence' => $sequence, 'message_id' => $delivery.'@facturly.test', 'created_at' => now(), 'updated_at' => now()]);
                self::outbox($doc, 'email', $delivery, $sequence);
                $response = ['data' => ['id' => $delivery, 'status' => 'pending']];
            }
            DB::table('document_delivery_requests')->insert(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_id' => $id, 'operation' => $operation, 'key' => $key, 'fingerprint' => $fingerprint, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $response;
        }, 3);
    }

    public function recover(): void
    {
        Document::where('status', 'accepted')->select(['id', 'company_id'])->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row): void {
                    $doc = Document::where('company_id', $row->company_id)->whereKey($row->id)->lockForUpdate()->firstOrFail();
                    self::ensurePdf($doc);
                    $pdf = DB::table('document_pdfs')->where('document_id', $doc->id)->lockForUpdate()->first();
                    if ($pdf->status === 'generating' && $pdf->lease_until && now()->gte($pdf->lease_until)) {
                        DB::table('document_delivery_attempts')->where('id', $pdf->active_attempt_id)->update(['status' => 'failed', 'error_code' => 'lease_expired', 'finished_at' => now()]);
                        DB::table('document_pdfs')->where('id', $pdf->id)->update(['status' => 'failed', 'active_attempt_id' => null, 'lease_until' => null, 'error_code' => 'lease_expired', 'next_attempt_at' => $pdf->attempts < 3 ? now()->addSeconds(30) : null, 'updated_at' => now()]);
                    }
                    $pdf = DB::table('document_pdfs')->where('id', $pdf->id)->first();
                    if ($pdf->status === 'failed' && $pdf->next_attempt_at && now()->gte($pdf->next_attempt_at) && $pdf->attempts < 3) {
                        $pdf->version++;
                        DB::table('document_pdfs')->where('id', $pdf->id)->update(['status' => 'pending', 'version' => $pdf->version, 'next_attempt_at' => null, 'updated_at' => now()]);
                        self::outbox($doc, 'pdf', $pdf->id, $pdf->version);
                        $pdf->status = 'pending';
                    }
                    if ($pdf->status === 'pending') {
                        $this->republish($doc->id, 'pdf', $pdf->version);
                    }
                    $emails = DB::table('document_email_deliveries')->where('document_id', $doc->id)->whereIn('status', ['pending', 'sending'])->lockForUpdate()->get();
                    foreach ($emails as $email) {
                        if ($email->status === 'sending' && $email->lease_until && now()->gte($email->lease_until)) {
                            DB::table('document_email_deliveries')->where('id', $email->id)->update(['status' => 'unknown', 'error_code' => 'delivery_unknown', 'lease_until' => null, 'active_attempt_id' => null, 'updated_at' => now()]);
                            DB::table('document_delivery_attempts')->where('id', $email->active_attempt_id)->update(['status' => 'unknown', 'error_code' => 'delivery_unknown', 'finished_at' => now()]);
                        } elseif ($email->status === 'pending') {
                            $this->republish($doc->id, 'email', $email->sequence);
                        }
                    }
                });
            }
        });
    }

    private function republish(string $document, string $kind, int $generation): void
    {
        DB::table('emission_outbox')->where(['document_id' => $document, 'kind' => $kind, 'generation' => $generation])->where('published_at', '<', now()->subSeconds(180))->update(['published_at' => null, 'available_at' => now()]);
    }

    /** Only unreferenced attempt files older than one day; retained versions are never deleted. */
    public function cleanOrphans(): int
    {
        $disk = Storage::disk('documents');
        $removed = 0;
        foreach ($disk->allFiles() as $path) {
            if (! preg_match('/\A[a-f0-9-]{36}\/[a-f0-9-]{36}\/[a-f0-9-]{36}\.(pdf|tmp)\z/', $path)) {
                continue;
            }if ($disk->lastModified($path) > now()->subDay()->timestamp) {
                continue;
            }if (DB::table('document_pdf_files')->where('disk', 'documents')->where('path', $path)->exists()) {
                continue;
            }$disk->delete($path);
            $removed++;
        }

        return $removed;
    }
}
