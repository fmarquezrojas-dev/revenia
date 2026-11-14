<?php

namespace App\Console\Commands;

use App\Actions\Delivery\DocumentDelivery;
use App\Actions\Issuance\EmissionOutbox;
use Illuminate\Console\Command;

final class PublishEmissions extends Command
{
    protected $signature = 'issuance:tick';

    protected $description = 'Recover interrupted simulated emissions and publish the transactional outbox';

    public function handle(EmissionOutbox $outbox): int
    {
        $outbox->recover();
        app(DocumentDelivery::class)->recover();
        $this->info('Published: '.$outbox->publish());

        return self::SUCCESS;
    }
}
