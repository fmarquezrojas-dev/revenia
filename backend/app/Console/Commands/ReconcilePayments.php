<?php

namespace App\Console\Commands;

use App\Actions\Payments\Collections;
use App\Actions\Payments\PaymentWork;
use App\Services\MercadoPagoGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--company= : UUID de empresa} {--order= : UUID de orden} {--work= : UUID de evento fallido} {--tick : Publicar trabajo durable sin nuevas consultas} {--limit=50}';

    protected $description = 'Conciliacion acotada de pagos de prueba; nunca repite a ciegas un checkout.';

    public function handle(PaymentWork $worker): int
    {
        if (! MercadoPagoGateway::transportReady()) {
            $this->warn('Mercado Pago de pruebas deshabilitado o incompleto.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $limit || $limit < 1 || $limit > 100) {
            $this->error('Limit debe estar entre 1 y 100.');

            return self::FAILURE;
        }
        $company = $this->option('company');
        if ($company && ! Str::isUuid($company)) {
            return self::FAILURE;
        }
        if (! $this->option('tick')) {
            if (! $company && ! $this->option('work')) {
                $this->error('Indica --company o un --work huerfano concreto.');

                return self::FAILURE;
            }
            foreach (['order', 'work'] as $option) {
                if ($this->option($option) && ! Str::isUuid($this->option($option))) {
                    return self::FAILURE;
                }
            }
            if ($this->option('work')) {
                $work = DB::table('payment_work')->where('company_id', $company)->where('id', $this->option('work'))->first();
                if (! $work || ! in_array($work->status, ['failed', 'review'], true)) {
                    $this->error('Evento no disponible.');

                    return self::FAILURE;
                }
                if ($work->kind === 'create') {
                    Collections::enqueue('reconcile', $company, $work->order_id, null, 'operator:'.Str::uuid());
                } else {
                    Collections::enqueue($work->kind, $company, $work->order_id, $work->payment_id, 'operator:'.Str::uuid(), $work->event_id, $work->webhook_scope, $work->connection_id, $work->webhook_collector_id);
                }
            } else {
                $orders = DB::table('payment_orders')->where('company_id', $company)->where('receiver_kind', '<>', 'legacy')->when($this->option('order'), fn ($q) => $q->where('id', $this->option('order')))->where(fn ($q) => $q->whereNull('last_reconciled_at')->orWhere('last_reconciled_at', '<', now()->subMinute()))->orderBy('created_at')->limit($limit)->get();
                foreach ($orders as $o) {
                    DB::table('payment_orders')->where('id', $o->id)->update(['last_reconciled_at' => now()]);
                    Collections::enqueue('reconcile', $company, $o->id, null, 'operator:'.Str::uuid());
                }
            }
        }
        $this->info('Publicaciones: '.$worker->tick($company, $limit));

        return self::SUCCESS;
    }
}
