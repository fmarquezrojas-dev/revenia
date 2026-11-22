<?php

namespace App\Http\Controllers;

use App\Actions\Payments\Receivables;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReceivablesController extends Controller
{
    public function __invoke(Request $request, Receivables $receivables): JsonResponse
    {
        Gate::authorize('viewAny', Document::class);
        $rules = Receivables::filterRules($request->filled('due_from'));
        if (array_diff(array_keys($request->query()), array_keys($rules))) {
            throw ValidationException::withMessages(['filters' => 'Filtro de cartera no admitido.']);
        }
        $filters = $request->validate($rules);
        $company = $request->user()->company_id;
        $cutoff = now('America/Lima')->toDateString();
        $data = DB::transaction(function () use ($receivables, $company, $filters, $cutoff): array {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $receivables->current($company, $filters, $cutoff);
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
