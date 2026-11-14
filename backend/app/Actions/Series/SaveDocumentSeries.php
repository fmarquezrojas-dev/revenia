<?php

namespace App\Actions\Series;

use App\Models\DocumentSeries;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveDocumentSeries
{
    public function handle(User $actor, array $data, ?DocumentSeries $series = null): DocumentSeries
    {
        try {
            return DB::transaction(function () use ($actor, $data, $series): DocumentSeries {
                if ($series) {
                    $series = DocumentSeries::query()
                        ->where('company_id', $actor->company_id)
                        ->whereKey($series->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    Gate::forUser($actor)->authorize('update', $series);
                } else {
                    Gate::forUser($actor)->authorize('create', DocumentSeries::class);
                    $series = new DocumentSeries;
                    $series->company_id = $actor->company_id;
                    $series->is_active = true;
                    $series->current_number = 0;
                }

                $series->fill(array_intersect_key($data, array_flip($series->exists ? ['is_active'] : ['doc_type', 'code'])));
                $series->save();

                return $series->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'series_company_type_code_unique')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'code' => ['Ya existe esta serie para el tipo de comprobante en tu empresa.'],
            ]);
        }
    }
}
