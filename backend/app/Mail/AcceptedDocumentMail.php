<?php

namespace App\Mail;

use App\Models\Document;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class AcceptedDocumentMail extends Mailable
{
    public function __construct(public Document $document, public object $file, public string $correlationId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[DEMO] '.config('app.name').' · '.($this->document->doc_type === '01' ? 'Factura' : 'Boleta').' '.$this->document->series_code.'-'.str_pad((string) $this->document->number, 8, '0', STR_PAD_LEFT));
    }

    public function headers(): Headers
    {
        return new Headers(messageId: $this->correlationId, text: ['X-Facturly-Delivery' => $this->correlationId]);
    }

    public function content(): Content
    {
        return new Content(view: 'documents.email', text: 'documents.email-text');
    }

    public function attachments(): array
    {
        return [Attachment::fromStorageDisk('documents', $this->file->path)->as($this->document->series_code.'-'.str_pad((string) $this->document->number, 8, '0', STR_PAD_LEFT).'.pdf')->withMime('application/pdf')];
    }
}
