<?php

namespace App\Http\Controllers;

use App\Actions\Payments\DocumentCash;
use App\Actions\Treasury\BankLedger;
use App\Actions\Treasury\BankReceipts;
use App\Domain\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BankReceiptController extends Controller
{
    private const PAGE = 'sometimes|integer|min:1|max:2147483647';

    private function available(string $ability): void
    {
        Gate::authorize($ability);
        abort_unless(DocumentCash::bankInstalled(), 503, 'Tesorería requiere aplicar la migración de cobros bancarios.');
    }

    private function read(\Closure $work): JsonResponse
    {
        $this->available('view-treasury');
        $data = DB::transaction(function () use ($work) {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $work();
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['account_id' => 'sometimes|required|uuid', 'customer_id' => 'sometimes|required|uuid', 'q' => 'sometimes|string|max:120',
            'currency' => 'sometimes|required|in:PEN,USD', 'situation' => ['sometimes', 'required', Rule::in(BankLedger::STATES)],
            'from' => 'sometimes|required|date_format:Y-m-d', 'to' => ['sometimes', 'required', 'date_format:Y-m-d',
                ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'page' => self::PAGE, 'company_id' => 'missing']);

        return $this->read(fn () => BankLedger::inbox($request->user()->company_id, $data));
    }

    public function accounts(Request $request): JsonResponse
    {
        return $this->read(fn () => BankLedger::accounts($request->user()->company_id));
    }

    public function saveAccount(Request $request, ?string $id = null): JsonResponse
    {
        $this->available('manage-treasury');
        $data = $request->validate(['alias' => 'required|string|max:80', 'institution' => 'required|string|max:100',
            'currency' => 'required|in:PEN,USD', 'active' => 'required|boolean', 'revision' => [$id ? 'required' : 'sometimes', 'integer', 'min:1'],
            'company_id' => 'missing']);
        BankReceipts::write($request->user(), 'manage-treasury', fn ($user) => BankReceipts::account($user, $id, $data));

        return response()->json(['saved' => true]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $filters = $request->validate(['page' => self::PAGE, 'history_page' => self::PAGE, 'application' => 'sometimes|required|uuid', 'company_id' => 'missing']);

        return $this->read(fn () => BankLedger::detail($request->user()->company_id, $id, $filters));
    }

    public function candidates(Request $request, string $id): JsonResponse
    {
        $filters = $request->validate(['page' => self::PAGE, 'company_id' => 'missing']);

        return $this->read(fn () => BankLedger::candidates($request->user()->company_id, $id, $filters));
    }

    public function store(Request $request, BankReceipts $service, ?string $id = null, string $action = 'register'): JsonResponse
    {
        $ability = $action === 'register' ? 'register-bank-receipt' : 'manage-treasury';
        $this->available($ability);
        $amount = ['required', 'integer', 'min:1', 'max:'.Money::MAX_CENTS];
        $rules = match ($action) {
            'register' => ['account_id' => 'required|uuid', 'customer_id' => 'nullable|uuid', 'bank_date' => 'required|date_format:Y-m-d',
                'method' => 'required|in:transfer,deposit', 'origin' => 'required|in:customer,mp_settlement',
                'currency' => 'required|in:PEN,USD', 'reference' => 'required|string|max:100', 'amount_cents' => $amount, 'note' => 'nullable|string|max:600'],
            'confirm' => ['verified' => 'accepted', 'origin' => 'required|in:customer,mp_settlement'],
            'identify' => ['customer_id' => 'required|uuid'],
            'apply' => ['lines' => 'required|array|list|min:1|max:50', 'lines.*' => 'required|array:document_id,amount_cents',
                'lines.*.document_id' => 'required|uuid|distinct:ignore_case', 'lines.*.amount_cents' => $amount],
            'reverse' => ['application_id' => 'required|uuid', 'reason' => 'required|string|max:600'],
        };
        if ($id) {
            $rules['revision'] = 'required|integer|min:1';
        }
        $data = $request->validate($rules + ['request_id' => 'required|uuid', 'company_id' => 'missing']);
        $result = BankReceipts::write($request->user(), $ability, fn ($user) => $service->execute($user, $action, $id, $data));

        return response()->json(['data' => $result])->header('Cache-Control', 'private, no-store');
    }
}
