<?php

namespace App\Http\Controllers;

use App\Actions\Payments\CollectionAgenda;
use App\Actions\Payments\CollectionFollowup;
use App\Actions\Payments\FollowupDetail;
use App\Domain\Money\Money;
use App\Models\Document;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CollectionFollowupController extends Controller
{
    private function snapshot(Closure $read): JsonResponse
    {
        $data = DB::transaction(function () use ($read) {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $read(now('America/Lima')->toDateString());
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function agenda(Request $request, CollectionAgenda $agenda): JsonResponse
    {
        Gate::authorize('viewAny', Document::class);
        $filters = $request->validate(['scope' => 'required|in:mine,team', 'currency' => 'sometimes|required|in:PEN,USD',
            'q' => 'sometimes|nullable|string|max:120', 'from' => 'sometimes|required|date_format:Y-m-d',
            'to' => ['sometimes', 'required', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'assignee' => 'sometimes|required|uuid', 'task' => ['sometimes', 'required', Rule::in(CollectionAgenda::TASKS)],
            'page' => 'sometimes|integer|min:1|max:2147483647', 'company_id' => 'missing']);
        Gate::authorize($filters['scope'] === 'team' ? 'viewTeamAgenda' : 'viewPersonalAgenda', Document::class);

        return $this->snapshot(fn ($today) => $agenda->page($request->user(), $filters, $today));
    }

    public function show(Request $request, string $id, FollowupDetail $detail): JsonResponse
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1|max:2147483647', 'company_id' => 'missing']);

        return $this->snapshot(fn ($today) => $detail->read($request->user(), CollectionFollowup::document($request->user(), $id), (int) ($data['page'] ?? 1), $today));
    }

    public function store(Request $request, string $id, string $action, CollectionFollowup $followup): JsonResponse
    {
        $text = ['required', 'string', 'max:1500', 'not_regex:/\x00/'];
        $rules = ['revision' => 'required|integer|min:0|max:2147483646', 'company_id' => 'missing',
            'actor_id' => 'missing', 'recorded_at' => 'missing'];
        if ($action === 'assign') {
            $rules['assignee_id'] = 'present|nullable|uuid';
        }
        if (in_array($action, ['contact', 'correct'], true)) {
            $rules += ['channel' => ['required', Rule::in(CollectionFollowup::CHANNELS)],
                'result' => ['required', Rule::in(CollectionFollowup::RESULTS)], 'note' => $text, 'next_action_on' => 'nullable|date_format:Y-m-d'];
        }
        if ($action === 'correct') {
            $rules['contact_id'] = 'required|uuid';
        }
        if (in_array($action, ['correct', 'reschedule', 'cancel'], true)) {
            $rules['reason'] = $text;
        }
        if (in_array($action, ['reschedule', 'cancel', 'fulfill'], true)) {
            $rules['promise_id'] = 'required|uuid';
        }
        if (in_array($action, ['promise', 'reschedule'], true)) {
            $rules += ['currency' => 'required|in:PEN,USD', 'amount_cents' => ['required', 'integer', 'min:1', 'max:'.Money::MAX_CENTS],
                'due_on' => 'required|date_format:Y-m-d'];
        }
        $data = $request->validate($rules);
        try {
            $followup->record($request->user(), $id, $action, $data);
        } catch (QueryException) {
            // SQL exception bindings can contain private contact notes.
            throw new HttpResponseException(response()->json(['message' => 'No se pudo guardar el seguimiento. Actualiza la ficha.'], 503));
        }

        return response()->json(['saved' => true])->header('Cache-Control', 'private, no-store');
    }
}
