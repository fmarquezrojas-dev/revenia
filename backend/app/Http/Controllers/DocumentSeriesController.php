<?php

namespace App\Http\Controllers;

use App\Actions\Series\SaveDocumentSeries;
use App\Http\Requests\SaveDocumentSeriesRequest;
use App\Http\Resources\DocumentSeriesResource;
use App\Models\DocumentSeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DocumentSeriesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', DocumentSeries::class);
        $filters = $request->validate([
            'doc_type' => ['sometimes', 'in:01,03,07'], 'is_active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'company_id' => ['missing'],
        ]);
        $query = DocumentSeries::query()->where('company_id', $request->user()->company_id);
        foreach (['doc_type', 'is_active'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }

        return DocumentSeriesResource::collection($query->orderBy('doc_type')->orderBy('code')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15))->withQueryString());
    }

    public function show(Request $request, string $seriesId): DocumentSeriesResource
    {
        $series = DocumentSeries::query()
            ->where('company_id', $request->user()->company_id)
            ->whereKey($seriesId)->firstOrFail();
        Gate::authorize('view', $series);
        $request->validate(['company_id' => ['missing']]);

        return new DocumentSeriesResource($series);
    }

    public function store(SaveDocumentSeriesRequest $request, SaveDocumentSeries $action): JsonResponse
    {
        return (new DocumentSeriesResource($action->handle($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(SaveDocumentSeriesRequest $request, SaveDocumentSeries $action): DocumentSeriesResource
    {
        return new DocumentSeriesResource($action->handle(
            $request->user(), $request->validated(), $request->targetSeries()
        ));
    }
}
