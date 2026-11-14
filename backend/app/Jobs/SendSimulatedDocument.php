<?php

namespace App\Jobs;

use App\Actions\Issuance\ProcessEmission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendSimulatedDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $companyId, public string $documentId, public int $generation) {}

    public function handle(ProcessEmission $action): void
    {
        $action->handle($this->companyId, $this->documentId, $this->generation);
    }
}
