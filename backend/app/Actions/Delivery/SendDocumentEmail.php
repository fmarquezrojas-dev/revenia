<?php

namespace App\Actions\Delivery;

use App\Mail\AcceptedDocumentMail;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class SendDocumentEmail
{
    public function handle(string $company, string $document, string $deliveryId): void
    {
        $delivery = DB::transaction(function () use ($company, $document, $deliveryId): ?object {
            $doc = Document::where('company_id', $company)->whereKey($document)->lockForUpdate()->first();
            if (! $doc || $doc->status !== 'accepted') {
                return null;
            }
            $row = DB::table('document_email_deliveries')->where(['id' => $deliveryId, 'company_id' => $company, 'document_id' => $document])->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending') {
                return null;
            }
            $row->active_attempt_id = (string) Str::uuid();
            DB::table('document_email_deliveries')->where('id', $row->id)->update(['status' => 'sending', 'active_attempt_id' => $row->active_attempt_id, 'lease_until' => now()->addSeconds(90), 'updated_at' => now()]);
            DB::table('document_delivery_attempts')->insert(['id' => $row->active_attempt_id, 'company_id' => $company, 'document_id' => $document, 'kind' => 'email', 'target_id' => $row->id, 'actor_id' => $row->requested_by, 'status' => 'sending', 'created_at' => now()]);

            return $row;
        });
        if (! $delivery) {
            return;
        }
        $doc = Document::where('company_id', $company)->whereKey($document)->firstOrFail();
        $file = DocumentDelivery::file($delivery->file_id, $company, $document);
        if (! DocumentDelivery::validFile($file)) {
            $this->complete($delivery, 'failed', 'pdf_unavailable');

            return;
        }
        $local = app()->environment(['local', 'testing']);
        $mailer = config('mail.default');
        $config = config('mail.mailers.'.$mailer, []);
        $allowed = $local ? (($config['transport'] ?? null) === 'smtp' && ($config['host'] ?? null) === 'mailpit' && empty($config['url'])) || app()->environment('testing') && ($config['transport'] ?? null) === 'array' : $delivery->recipient === config('delivery.demo_recipient') && filter_var($delivery->recipient, FILTER_VALIDATE_EMAIL) && ($config['transport'] ?? null) === 'smtp';
        if (! $allowed) {
            $this->complete($delivery, 'failed', 'demo_transport_blocked');

            return;
        }
        try {
            $mail = new AcceptedDocumentMail($doc, $file, $delivery->message_id);
            // Build views before entering the SMTP uncertainty window.
            view('documents.email', ['document' => $doc])->render();
            view('documents.email-text', ['document' => $doc])->render();
        } catch (\Throwable) {
            $this->complete($delivery, 'failed', 'email_render_failed');

            return;
        }
        try {
            Mail::to($delivery->recipient)->send($mail);
            $this->complete($delivery, 'sent', null);
        } catch (\Throwable) {
            $this->complete($delivery, 'unknown', 'delivery_unknown');
        }
    }

    private function complete(object $delivery, string $status, ?string $code): void
    {
        DB::transaction(function () use ($delivery, $status, $code): void {
            $row = DB::table('document_email_deliveries')->where('id', $delivery->id)->lockForUpdate()->first();
            if ($row->status !== 'sending' || $row->active_attempt_id !== $delivery->active_attempt_id || now()->gte($row->lease_until)) {
                return;
            }
            DB::table('document_email_deliveries')->where('id', $row->id)->update(['status' => $status, 'error_code' => $code, 'active_attempt_id' => null, 'lease_until' => null, 'sent_at' => $status === 'sent' ? now() : null, 'updated_at' => now()]);
            DB::table('document_delivery_attempts')->where('id', $delivery->active_attempt_id)->update(['status' => $status, 'error_code' => $code, 'finished_at' => now()]);
        });
    }
}
