<?php

namespace App\Jobs;

use App\Actions\Payments\PaymentWork;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPaymentWork implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $workId) {}

    public function handle(PaymentWork $worker): void
    {
        $worker->run($this->workId);
    }
}
