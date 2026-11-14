<?php

namespace Tests\Feature;

use App\Actions\Series\ReserveNextNumber;
use App\Models\DocumentSeries;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\DatabaseTestCase;

class ReserveNextNumberTest extends DatabaseTestCase
{
    public function test_rejects_call_without_transaction_on_supplied_connection(): void
    {
        config(['database.connections.series_outside' => config('database.connections.pgsql')]);
        $connection = DB::connection('series_outside');
        try {
            $this->assertSame(0, $connection->transactionLevel());
            $this->expectException(LogicException::class);
            app(ReserveNextNumber::class)->handle($connection, 'unused', 'unused');
        } finally {
            DB::purge('series_outside');
        }
    }

    public function test_increment_and_rollback_use_callers_transaction(): void
    {
        $series = DocumentSeries::factory()->create();
        $connection = DB::connection();
        $action = app(ReserveNextNumber::class);
        $level = $connection->transactionLevel();
        try {
            $connection->transaction(function () use ($action, $connection, $series): void {
                $this->assertSame(1, $action->handle($connection, $series->company_id, $series->id));
                $this->assertSame(2, $action->handle($connection, $series->company_id, $series->id));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertSame(0, $series->fresh()->current_number);
        $this->assertSame($level, $connection->transactionLevel());
        $this->assertSame(1, $action->handle($connection, $series->company_id, $series->id));
        $this->assertSame(1, $series->fresh()->current_number);
        $this->assertSame($level, $connection->transactionLevel());
    }

    public function test_inactive_series_does_not_consume_number(): void
    {
        $series = DocumentSeries::factory()->create(['is_active' => false]);
        try {
            app(ReserveNextNumber::class)->handle(DB::connection(), $series->company_id, $series->id);
            $this->fail('Inactive series must fail.');
        } catch (DomainException) {
            $this->assertSame(0, $series->fresh()->current_number);
        }
    }

    public function test_foreign_series_is_not_found(): void
    {
        $series = DocumentSeries::factory()->create();
        $other = DocumentSeries::factory()->create();
        $this->expectException(ModelNotFoundException::class);
        app(ReserveNextNumber::class)->handle(DB::connection(), $other->company_id, $series->id);
    }

    public function test_last_available_number_and_exhaustion(): void
    {
        $series = DocumentSeries::factory()->create(['current_number' => 99999998]);
        $action = app(ReserveNextNumber::class);
        $this->assertSame(99999999, $action->handle(DB::connection(), $series->company_id, $series->id));
        try {
            $action->handle(DB::connection(), $series->company_id, $series->id);
            $this->fail('Exhausted series must fail.');
        } catch (DomainException) {
            $this->assertSame(99999999, $series->fresh()->current_number);
        }
    }
}
