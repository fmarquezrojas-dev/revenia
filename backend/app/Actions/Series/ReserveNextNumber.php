<?php

namespace App\Actions\Series;

use App\Domain\Series\DocumentNumber;
use App\Models\DocumentSeries;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/** The caller must commit the document and this increment on this exact connection. */
final class ReserveNextNumber
{
    public function handle(Connection $connection, string $companyId, string $seriesId): int
    {
        if ($connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()) {
            throw new LogicException('La asignacion requiere una transaccion abierta por el llamador.');
        }
        $query = $connection->table('document_series')->where('company_id', $companyId)->where('id', $seriesId);
        $series = (clone $query)->lockForUpdate()->first();
        if (! $series) {
            throw (new ModelNotFoundException)->setModel(DocumentSeries::class, [$seriesId]);
        }
        if (! $series->is_active) {
            throw new DomainException('La serie esta inactiva.');
        }
        if ((int) $series->current_number >= DocumentNumber::MAX_NUMBER) {
            throw new DomainException('La serie agoto el correlativo 99999999.');
        }
        $next = (int) $series->current_number + 1;
        $query->update(['current_number' => $next, 'updated_at' => now()]);

        return $next;
    }
}
