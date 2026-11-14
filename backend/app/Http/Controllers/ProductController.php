<?php

namespace App\Http\Controllers;

use App\Actions\Products\SaveProduct;
use App\Http\Requests\SaveProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
            'currency' => ['sometimes', 'in:PEN,USD'],
            'tax_type' => ['sometimes', 'in:10,20,30'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'company_id' => ['missing'],
        ]);

        $query = Product::query()->where('company_id', $request->user()->company_id);
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }
        foreach (['currency', 'tax_type'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($filters['q'] ?? '') !== '') {
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])).'%';
            $query->where(fn ($query) => $query->where('description', 'ilike', $search)->orWhere('code', 'ilike', $search));
        }

        return ProductResource::collection(
            $query->orderBy('description')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 15))->withQueryString()
        );
    }

    public function show(Request $request, string $productId): ProductResource
    {
        $product = Product::query()
            ->where('company_id', $request->user()->company_id)
            ->whereKey($productId)->firstOrFail();
        Gate::authorize('view', $product);
        $request->validate(['company_id' => ['missing']]);

        return new ProductResource($product);
    }

    public function store(SaveProductRequest $request, SaveProduct $action): JsonResponse
    {
        return (new ProductResource($action->handle($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(SaveProductRequest $request, SaveProduct $action): ProductResource
    {
        return new ProductResource($action->handle(
            $request->user(), $request->validated(), $request->targetProduct()
        ));
    }
}
