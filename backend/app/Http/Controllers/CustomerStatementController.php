<?php

namespace App\Http\Controllers;

use App\Actions\Customers\CustomerStatement;
use App\Models\Customer;
use App\Models\Document;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CustomerStatementController extends Controller
{
    public function __invoke(Request $request, string $customerId, CustomerStatement $statement): JsonResponse
    {
        $page = 'sometimes|required|integer|min:1|max:2147483647';
        $rules = ['currency' => 'sometimes|required|in:PEN,USD', 'from' => 'required_with:to|date_format:Y-m-d',
            'to' => 'required_with:from|date_format:Y-m-d|after_or_equal:from', 'document' => 'sometimes|required|uuid',
            'page' => $page, 'movements_page' => $page, 'receipts_page' => $page, 'view' => 'sometimes|required|in:print'];
        if (array_diff(array_keys($request->query()), array_keys($rules))) {
            throw ValidationException::withMessages(['filters' => 'Filtro de estado de cuenta no admitido.']);
        }
        $filters = $request->validate($rules);
        $data = DB::transaction(function () use ($request, $customerId, $statement, $filters) {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $customer = Customer::where('company_id', $request->user()->company_id)->findOrFail($customerId);
            Gate::authorize('view', $customer);
            Gate::authorize('viewAny', Document::class);
            Gate::authorize('view-treasury');

            return $statement->read($customer, $filters, CarbonImmutable::now('America/Lima'));
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
