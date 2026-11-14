<?php

namespace App\Actions\Delivery;

use App\Models\Document;
use App\Services\DocumentPdfRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class GenerateDocumentPdf
{
    public function __construct(private DocumentPdfRenderer $renderer) {}

    public function handle(string $company, string $document, int $version): void
    {
        $claim = DB::transaction(function () use ($company, $document, $version): ?object {
            $doc = Document::where('company_id', $company)->whereKey($document)->lockForUpdate()->first();
            if (! $doc || $doc->status !== 'accepted') {
                return null;
            }
            $pdf = DB::table('document_pdfs')->where(['company_id' => $company, 'document_id' => $document])->lockForUpdate()->first();
            if (! $pdf || $pdf->status !== 'pending' || $pdf->version !== $version || $pdf->attempts >= 5) {
                return null;
            }
            $pdf->active_attempt_id = (string) Str::uuid();
            $pdf->attempts++;
            DB::table('document_pdfs')->where('id', $pdf->id)->update(['status' => 'generating', 'attempts' => $pdf->attempts, 'active_attempt_id' => $pdf->active_attempt_id, 'lease_until' => now()->addSeconds(90), 'error_code' => null, 'updated_at' => now()]);
            DB::table('document_delivery_attempts')->insert(['id' => $pdf->active_attempt_id, 'company_id' => $company, 'document_id' => $document, 'kind' => 'pdf', 'target_id' => $pdf->id, 'actor_id' => $pdf->requested_by, 'status' => 'generating', 'created_at' => now()]);

            return $pdf;
        });
        if (! $claim) {
            return;
        }$path = $company.'/'.$document.'/'.$claim->active_attempt_id.'.pdf';
        $temporary = substr($path, 0, -4).'.tmp';
        $disk = Storage::disk('documents');
        $published = false;
        try {
            $doc = Document::where('company_id', $company)->whereKey($document)->firstOrFail();
            $bytes = $this->renderer->render($doc);
            if (! str_starts_with($bytes, '%PDF-') || ! str_contains(substr($bytes, -1024), '%%EOF') || strlen($bytes) < 500) {
                throw new \RuntimeException('invalid_pdf');
            }
            $disk->put($temporary, $bytes);
            if ($disk->size($temporary) !== strlen($bytes)) {
                throw new \RuntimeException('write_failed');
            }
            if (! $disk->move($temporary, $path)) {
                throw new \RuntimeException('publish_failed');
            }
            $published = DB::transaction(function () use ($claim, $company, $document, $path, $bytes): bool {
                $pdf = DB::table('document_pdfs')->where('id', $claim->id)->lockForUpdate()->first();
                if ($pdf->status !== 'generating' || $pdf->active_attempt_id !== $claim->active_attempt_id || now()->gte($pdf->lease_until)) {
                    return false;
                }
                $file = (string) Str::uuid();
                DB::table('document_pdf_files')->insert(['id' => $file, 'company_id' => $company, 'document_id' => $document, 'attempt_id' => $claim->active_attempt_id, 'disk' => 'documents', 'path' => $path, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'template_version' => config('delivery.template_version'), 'created_at' => now()]);
                DB::table('document_pdfs')->where('id', $pdf->id)->update(['status' => 'ready', 'current_file_id' => $file, 'active_attempt_id' => null, 'lease_until' => null, 'next_attempt_at' => null, 'error_code' => null, 'updated_at' => now()]);
                DB::table('document_delivery_attempts')->where('id', $claim->active_attempt_id)->update(['status' => 'ready', 'finished_at' => now()]);

                return true;
            });
        } catch (\Throwable $error) {
            $code = $error instanceof \DomainException ? 'snapshot_incomplete' : 'pdf_generation_failed';
            DB::transaction(function () use ($claim, $code): void {
                $pdf = DB::table('document_pdfs')->where('id', $claim->id)->lockForUpdate()->first();
                if ($pdf->status !== 'generating' || $pdf->active_attempt_id !== $claim->active_attempt_id) {
                    return;
                }
                DB::table('document_pdfs')->where('id', $pdf->id)->update(['status' => 'failed', 'active_attempt_id' => null, 'lease_until' => null, 'error_code' => $code, 'next_attempt_at' => $code !== 'snapshot_incomplete' && $claim->attempts < 3 ? now()->addSeconds(30 * $claim->attempts) : null, 'updated_at' => now()]);
                DB::table('document_delivery_attempts')->where('id', $claim->active_attempt_id)->update(['status' => 'failed', 'error_code' => $code, 'finished_at' => now()]);
            });
        } finally {
            try {
                $disk->delete($temporary);
                if (! $published && ! DB::table('document_pdf_files')->where('path', $path)->exists()) {
                    $disk->delete($path);
                }
            } catch (\Throwable) {/* A daily orphan sweep handles interrupted cleanup. */
            }
        }
    }
}
