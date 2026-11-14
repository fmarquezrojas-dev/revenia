<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CompanyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        abort_if($company === null, 404);

        Gate::authorize('view', $company);

        return $this->companyResponse($company);
    }

    public function update(
        UpdateCompanyRequest $request,
    ): JsonResponse {
        $company = $request->user()->company;

        $company->fill($request->validated());
        $company->save();

        return $this->companyResponse($company->refresh());
    }

    private function companyResponse(
        Company $company,
    ): JsonResponse {
        return response()->json([
            'data' => [
                'id' => $company->id,
                'ruc' => $company->ruc,
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'address' => $company->address,
                'email' => $company->email,
                'is_active' => $company->is_active,
            ],
        ]);
    }
}
