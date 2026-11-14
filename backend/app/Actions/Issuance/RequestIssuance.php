<?php

namespace App\Actions\Issuance;

use App\Actions\Documents\DraftDocuments;
use App\Actions\Series\ReserveNextNumber;
use App\Actions\Subscriptions\Subscriptions;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RequestIssuance
{
    public static function conflict(string $message): never
    {
        throw new HttpResponseException(response()->json(['code' => 'emission_conflict', 'message' => $message], 409));
    }

    public static function event(Document $doc, string $type, string $from, ?string $actor = null, ?string $attempt = null): void
    {
        DB::table('document_events')->insert(['id' => (string) Str::uuid7(), 'company_id' => $doc->company_id, 'document_id' => $doc->id, 'actor_id' => $actor, 'attempt_id' => $attempt, 'type' => $type, 'from_status' => $from, 'to_status' => $doc->status, 'code' => $doc->result_code, 'message' => $doc->result_message, 'created_at' => now()]);
    }

    public static function enqueue(Document $doc): void
    {
        DB::table('emission_outbox')->insert(['id' => (string) Str::uuid(), 'company_id' => $doc->company_id, 'document_id' => $doc->id, 'generation' => $doc->version, 'available_at' => now(), 'created_at' => now()]);
    }

    public function handle(User $actor, string $id, int $version, string $key, string $operation = 'issue'): array
    {
        return DB::transaction(function () use ($actor, $id, $version, $key, $operation): array {
            // A company lock serializes absent idempotency keys and establishes one lock order.
            $company = Company::whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($actor->id)->where('company_id', $company->id)->sharedLock()->firstOrFail();
            abort_unless($actor->is_active && $company->is_active, 401);
            $actor->setRelation('company', $company);
            $doc = Document::where('company_id', $company->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $doc);
            $fingerprint = hash('sha256', json_encode([$operation, $id, $version], JSON_THROW_ON_ERROR));
            $previous = DB::table('emission_requests')->where(['company_id' => $company->id, 'operation' => $operation, 'key' => $key])->first();
            if ($previous) {
                if (! hash_equals($previous->fingerprint, $fingerprint)) {
                    self::conflict('La clave ya corresponde a otra solicitud.');
                }

                return json_decode($previous->response, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($doc->version !== $version) {
                self::conflict('El comprobante cambio. Actualiza y revisa su version.');
            }
            $from = $doc->status;
            if ($operation === 'issue') {
                if ($doc->status !== 'draft') {
                    self::conflict('El comprobante ya tiene una emision registrada.');
                }
                app(Subscriptions::class)->consume($doc);
                $series = DocumentSeries::where('company_id', $company->id)->whereKey($doc->series_id)->lockForUpdate()->firstOrFail();
                $data = ['version' => $version, 'series_id' => $doc->series_id, 'customer_id' => $doc->customer_id, 'doc_type' => $doc->doc_type, 'currency' => $doc->currency, 'issue_date' => $doc->issue_date->format('Y-m-d'), 'items' => $doc->items->map(fn ($line) => ['id' => $line->id, 'product_id' => $line->product_id, 'quantity' => intdiv($line->quantity_millis, 1000).'.'.str_pad((string) ($line->quantity_millis % 1000), 3, '0', STR_PAD_LEFT)])->all()];
                Validator::make($data, ['doc_type' => 'required|in:01,03', 'currency' => 'required|in:PEN,USD', 'issue_date' => 'required|date_format:Y-m-d|before_or_equal:'.now('America/Lima')->toDateString(), 'items' => 'required|array|min:1|max:50', 'items.*.product_id' => 'required|uuid|distinct:strict'])->validate();
                $preview = app(DraftDocuments::class)->save($actor, $data, $id, true);
                foreach ($preview['items'] as $line) {
                    $doc->items()->whereKey($line['id'])->update(array_intersect_key($line, array_flip(['base_cents', 'igv_cents', 'total_cents'])));
                }
                $doc->fill($preview['totals']);
                try {
                    $number = app(ReserveNextNumber::class)->handle(DB::connection(), $company->id, $series->id);
                } catch (\DomainException $e) {
                    throw ValidationException::withMessages(['series_id' => $e->getMessage()]);
                }
                $doc->load('customer');
                $doc->fiscal_snapshot = ['issuer' => $company->only(['ruc', 'legal_name', 'trade_name', 'address']), 'customer' => $doc->customer?->only(['id', 'name', 'doc_type', 'doc_number', 'address', 'email', 'is_active']), 'series' => $series->only(['id', 'code', 'doc_type', 'is_active'])];
                $doc->number = $number;
                $doc->series_code = $series->code;
                $doc->submission_id = (string) Str::uuid();
                $doc->issued_at = now();
            } elseif ($operation === 'retry') {
                if ($doc->status !== 'failed' || ! $doc->can_retry || $doc->attempt_count >= 5) {
                    self::conflict('Este comprobante no permite reintentos.');
                }
            } else {
                abort(422);
            }
            $doc->status = 'queued';
            $doc->version++;
            $doc->can_retry = false;
            $doc->next_attempt_at = null;
            $doc->result_code = null;
            $doc->result_message = null;
            $doc->failure_kind = null;
            $doc->save();
            if ($operation === 'issue') {
                self::event($doc, 'number_assigned', $from, $actor->id);
            }
            self::event($doc, $operation === 'issue' ? 'issuance_requested' : 'retry_requested', $from, $actor->id);
            self::enqueue($doc);
            $response = ['data' => ['id' => $doc->id, 'number' => $doc->number, 'formatted_number' => $doc->series_code.'-'.str_pad((string) $doc->number, 8, '0', STR_PAD_LEFT), 'status' => $doc->status, 'version' => $doc->version, 'status_url' => '/api/v1/documents/'.$doc->id]];
            DB::table('emission_requests')->insert(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_id' => $id, 'operation' => $operation, 'key' => $key, 'fingerprint' => $fingerprint, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $response;
        }, 3);
    }
}
