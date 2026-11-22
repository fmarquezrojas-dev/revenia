<?php

use App\Actions\Delivery\DocumentDelivery;
use App\Actions\Odoo\OdooTransfers;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('issuance:tick')->everyMinute();
Schedule::call(fn () => OdooTransfers::tick())->name('odoo:publish')->everyMinute()->withoutOverlapping();

Artisan::command('delivery:clean-orphans', function (): void {
    $this->info('Removed: '.app(DocumentDelivery::class)->cleanOrphans());
})->purpose('Remove unreferenced private attempt files older than 24 hours');
Schedule::command('delivery:clean-orphans')->daily();
Artisan::command('delivery:resolve {delivery} {result} {--confirmed}', function (): int {
    if (! $this->option('confirmed') || ! in_array($this->argument('result'), ['sent', 'failed'], true)) {
        $this->error('Inspect the transport by message_id first; use sent or failed and --confirmed.');

        return 1;
    }

    return DB::transaction(function (): int {
        $row = DB::table('document_email_deliveries')->where('id', $this->argument('delivery'))->lockForUpdate()->first();
        if (! $row || $row->status !== 'unknown') {
            $this->error('Only unknown deliveries may be reconciled.');

            return 1;
        }
        $status = $this->argument('result');
        DB::table('document_email_deliveries')->where('id', $row->id)->update(['status' => $status, 'error_code' => 'transport_reviewed', 'sent_at' => $status === 'sent' ? now() : null, 'updated_at' => now()]);
        DB::table('document_delivery_attempts')->insert(['id' => (string) Str::uuid(), 'company_id' => $row->company_id, 'document_id' => $row->document_id, 'kind' => 'email_review', 'target_id' => $row->id, 'status' => $status, 'error_code' => 'transport_reviewed', 'created_at' => now(), 'finished_at' => now()]);
        $this->info('Transport result recorded. No email was sent.');

        return 0;
    });
})->purpose('Record a manually verified transport outcome without resending');

Schedule::command('payments:reconcile --tick --limit=50')->everyMinute()->withoutOverlapping();

Schedule::command('subscriptions:maintain --limit=100')->hourly()->withoutOverlapping();
