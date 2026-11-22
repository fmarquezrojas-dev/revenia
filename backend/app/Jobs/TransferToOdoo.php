<?php

namespace App\Jobs;

use App\Actions\Odoo\OdooTransfers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class TransferToOdoo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public function __construct(public string $company, public string $transfer) {}

    public function handle(OdooTransfers $service): void
    {
        $service->run($this->company, $this->transfer);
    }
}
