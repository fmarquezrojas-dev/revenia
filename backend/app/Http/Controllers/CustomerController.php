<?php

namespace App\Http\Controllers;

use App\Actions\Customers\SaveCustomer;
use App\Http\Requests\SaveCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Customer::class);
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'company_id' => ['missing'],
        ]);

        $query = Customer::query()->where('company_id', $request->user()->company_id);
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }
        if (($filters['q'] ?? '') !== '') {
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])).'%';
            $query->where(fn ($query) => $query->where('name', 'ilike', $search)->orWhere('doc_number', 'ilike', $search));
        }

        return CustomerResource::collection(
            $query->orderBy('name')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 15))->withQueryString()
        );
    }

    public function show(Request $request, string $customerId): CustomerResource
    {
        $customer = Customer::query()
            ->where('company_id', $request->user()->company_id)
            ->whereKey($customerId)->firstOrFail();
        Gate::authorize('view', $customer);

        return new CustomerResource($customer);
    }

    public function store(SaveCustomerRequest $request, SaveCustomer $action): JsonResponse
    {
        return (new CustomerResource($action->handle($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(SaveCustomerRequest $request, SaveCustomer $action): CustomerResource
    {
        return new CustomerResource($action->handle(
            $request->user(), $request->validated(), $request->targetCustomer()
        ));
    }
}
