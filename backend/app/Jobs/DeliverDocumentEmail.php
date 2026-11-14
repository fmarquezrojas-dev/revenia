<?php

namespace App\Jobs;

use App\Actions\Delivery\SendDocumentEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverDocumentEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $companyId, public string $documentId, public string $deliveryId) {}

    public function handle(SendDocumentEmail $action): void
    {
        $action->handle($this->companyId, $this->documentId, $this->deliveryId);
    }
}
