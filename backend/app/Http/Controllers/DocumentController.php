<?php

namespace App\Http\Controllers;

use App\Actions\Documents\DraftDocuments;
use App\Http\Requests\SaveDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentSeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Document::class);
        $f = $request->validate(['doc_type' => ['sometimes', 'in:01,03'], 'status' => ['sometimes', 'in:draft,issued,queued,sending,accepted,rejected,failed'], 'customer_id' => ['sometimes', 'uuid'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'], 'date_to' => ['sometimes', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'company_id' => ['missing']]);
        $q = Document::where('company_id', $request->user()->company_id)->with(['items.product', 'customer', 'series']);
        foreach (['doc_type', 'status', 'customer_id'] as $field) {
            if (isset($f[$field])) {
                $q->where($field, $f[$field]);
            }
        }
        if (isset($f['date_from'])) {
            $q->where('issue_date', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->where('issue_date', '<=', $f['date_to']);
        }

        return DocumentResource::collection($q->orderByDesc('issue_date')->orderByDesc('id')->paginate($f['per_page'] ?? 15)->withQueryString());
    }

    public function show(Request $request, string $documentId): DocumentResource
    {
        $doc = Document::where('company_id', $request->user()->company_id)->whereKey($documentId)->with(['items.product', 'customer', 'series'])->firstOrFail();
        Gate::authorize('view', $doc);

        return new DocumentResource($doc);
    }

    public function store(SaveDocumentRequest $request, DraftDocuments $action): JsonResponse
    {
        return (new DocumentResource($action->save($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function update(SaveDocumentRequest $request, string $documentId, DraftDocuments $action): DocumentResource
    {
        return new DocumentResource($action->save($request->user(), $request->validated(), $documentId));
    }

    public function preview(SaveDocumentRequest $request, DraftDocuments $action): JsonResponse
    {
        return response()->json(['data' => $action->save($request->user(), $request->validated(), $request->input('document_id'), true)]);
    }

    public function destroy(Request $request, string $documentId, DraftDocuments $action): Response
    {
        $doc = Document::where('company_id', $request->user()->company_id)->whereKey($documentId)->firstOrFail();
        Gate::authorize('delete', $doc);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646'], 'company_id' => ['missing']]);
        $action->delete($request->user(), $documentId, (int) $data['version']);

        return response()->noContent();
    }

    public function seriesOptions(Request $request): JsonResponse
    {
        Gate::authorize('create', Document::class);
        $f = $request->validate(['doc_type' => ['required', 'in:01,03'], 'company_id' => ['missing']]);

        return response()->json(['data' => DocumentSeries::where('company_id', $request->user()->company_id)->where('is_active', true)->where('doc_type', $f['doc_type'])->orderBy('code')->get(['id', 'code', 'doc_type'])]);
    }
}
