<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\BusinessDashboard;
use App\Models\Document;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function __invoke(Request $request, BusinessDashboard $dashboard): JsonResponse
    {
        Gate::authorize('viewAny', Document::class);
        $data = $request->validate(['from' => ['sometimes', 'required', 'date_format:Y-m-d'], 'to' => ['sometimes', 'required', 'date_format:Y-m-d'], 'currency' => ['sometimes', 'required', 'in:PEN,USD'], 'metric' => ['sometimes', 'required', Rule::in(BusinessDashboard::METRICS)], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'company_id' => ['missing'], 'group_by' => ['sometimes', 'in:day']]);
        $unknown = array_diff(array_keys($request->query()), ['from', 'to', 'currency', 'metric', 'page', 'group_by']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['filters' => 'Filtro no admitido.']);
        }
        $filters = BusinessDashboard::period($data, CarbonImmutable::now('America/Lima'));
        $result = DB::transaction(function () use ($request, $dashboard, $filters, $data): array {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return isset($data['metric']) ? ['currency' => $filters['currency'], 'period' => $filters, 'timezone' => 'America/Lima', 'calculated_at' => now()->toIso8601String(), 'detail' => $dashboard->details($request->user()->company_id, $filters, $data['metric'], (int) ($data['page'] ?? 1))] : $dashboard->summary($request->user(), $filters);
        });

        return response()->json(['data' => $result])->header('Cache-Control', 'private, no-store');
    }
}
