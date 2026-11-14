<?php

namespace App\Jobs;

use App\Actions\Delivery\GenerateDocumentPdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GeneratePrivatePdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $companyId, public string $documentId, public int $version) {}

    public function handle(GenerateDocumentPdf $action): void
    {
        $action->handle($this->companyId, $this->documentId, $this->version);
    }
}
