<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class VerifyQueue implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::info(config('app.name').': trabajo de prueba procesado por la cola.');
    }
}
