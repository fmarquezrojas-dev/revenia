<?php

namespace App\Http\Controllers;

use App\Actions\Delivery\DocumentDelivery;
use App\Actions\Incidents\IncidentHistory;
use App\Actions\Incidents\IncidentInbox;
use App\Actions\Issuance\RequestIssuance;
use App\Actions\Odoo\OdooTransfers;
use App\Actions\Payments\Collections;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class IncidentController extends Controller
{
    private function read(callable $read): JsonResponse
    {
        Gate::authorize('viewAny', Document::class);
        $data = DB::transaction(static function () use ($read) {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $read();
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $rules = ['process' => ['sometimes', Rule::in(IncidentInbox::PROCESSES)], 'state' => ['sometimes', Rule::in(IncidentInbox::STATES)],
            'alert' => ['sometimes', Rule::in(IncidentInbox::ALERTS)], 'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'], 'q' => ['sometimes', 'string', 'max:100'],
            'currency' => ['sometimes', 'in:PEN,USD'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']];
        if (array_diff(array_keys($request->query()), array_keys($rules))) {
            throw ValidationException::withMessages(['filters' => 'Filtro no admitido.']);
        }
        $filters = $request->validate($rules);
        if (isset($filters['from'],$filters['to']) && $filters['from'] > $filters['to']) {
            throw ValidationException::withMessages(['to' => 'La fecha final debe ser igual o posterior a la inicial.']);
        }

        return $this->read(function () use ($request, $filters): array {
            $actor = $request->user();
            $permissions = IncidentInbox::permissions($actor);
            $rows = IncidentInbox::filtered($actor->company_id, $filters)->orderByDesc('i.updated_at')->orderBy('i.process')
                ->orderBy('i.operation_id')->paginate(20, ['*'], 'page', (int) ($filters['page'] ?? 1));

            return ['rows' => array_map(fn ($r) => IncidentInbox::present($r, $permissions), $rows->items()),
                'page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()];
        });
    }

    public function show(Request $request, string $process, string $operation): JsonResponse
    {
        $input = $request->validate(['history_page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'company_id' => ['missing']]);

        return $this->read(function () use ($request, $process, $operation, $input): array {
            $actor = $request->user();
            $row = IncidentInbox::find($actor->company_id, $process, $operation);

            return IncidentInbox::detail($actor, $row) + ['history' => IncidentHistory::page($actor->company_id, $row, (int) ($input['history_page'] ?? 1))];
        });
    }

    public function act(Request $request, string $process, string $operation): JsonResponse
    {
        $input = Validator::make(['payload' => $request->all(), 'key' => $request->header('Idempotency-Key')], [
            'key' => 'required|uuid', 'payload' => 'required|array:action,revision',
            'payload.action' => ['required', Rule::in(['reviewed', 'retry_emission', 'regenerate_pdf', 'retry_email', 'reconcile', 'retry_odoo'])],
            'payload.revision' => ['required', 'string', 'max:100', 'regex:/\A[0-9a-f-]{36}:(?:\d{1,10}|[0-9a-f-]{36})\z/i'],
        ])->validate();
        $action = $input['payload']['action'];
        $revision = $input['payload']['revision'];
        $key = $input['key'];
        [$status,$outcome] = DB::transaction(function () use ($request, $process, $operation, $action, $revision, $key): array {
            $company = Company::whereKey($request->user()->company_id)->lockForUpdate()->firstOrFail();
            $actor = User::where('company_id', $company->id)->whereKey($request->user()->id)->sharedLock()->firstOrFail();
            $actor->setRelation('company', $company);
            Gate::forUser($actor)->authorize('viewAny', Document::class);
            $row = IncidentInbox::find($company->id, $process, $operation);
            abort_unless($row->document_id, 403);
            $operation = $row->operation_id;
            $doc = Document::where('company_id', $company->id)->whereKey($row->document_id)->lockForUpdate()->firstOrFail();
            $ability = match ($action) {
                'retry_odoo' => 'transferToOdoo', 'regenerate_pdf' => 'regeneratePdf','retry_email' => 'sendEmail','reconcile' => 'reconcilePayment',default => 'update',
            };
            Gate::forUser($actor)->authorize($ability, $doc);
            $previous = DB::table('incident_follow_ups')->where('company_id', $company->id)->where('request_key', $key)->first();
            if ($previous) {
                if ($previous->actor_id !== $actor->id || $previous->process !== $process || $previous->operation_id !== $operation
                    || $previous->action !== $action || $previous->revision !== $revision) {
                    return [409, 'state_changed'];
                }

                return [(int) $previous->http_status, $previous->outcome];
            }
            $row = IncidentInbox::find($company->id, $process, $operation);
            $detail = IncidentInbox::detail($actor, $row);
            if ($action === 'reviewed') {
                [$status,$outcome] = [200, 'recorded'];
            } elseif ($action !== $detail['action'] || $revision !== $detail['revision']) {
                [$status,$outcome] = [409, 'state_changed'];
            } else {
                try {
                    $domainKey = hash('sha256', 'incidents:'.$key);
                    match ($action) {
                        'retry_odoo' => OdooTransfers::retry($actor, $operation),
                        'retry_emission' => app(RequestIssuance::class)->handle($actor, $doc->id, (int) $row->revision, $domainKey, 'retry'),
                        'regenerate_pdf' => app(DocumentDelivery::class)->request($actor, $doc->id, 'pdf', $domainKey, ['version' => (int) $row->revision]),
                        'retry_email' => app(DocumentDelivery::class)->request($actor, $doc->id, 'email', $domainKey, ['file_id' => explode(':', $revision)[1]]),
                        'reconcile' => app(Collections::class)->request($actor, $doc->id, $domainKey, 'reconcile', $operation),
                    };
                    [$status,$outcome] = [202, 'requested'];
                } catch (HttpResponseException $e) {
                    $status = $e->getResponse()->getStatusCode();
                    $outcome = $status === 429 ? 'rate_limited' : 'unavailable';
                } catch (HttpExceptionInterface $e) {
                    if ($action !== 'retry_odoo' || $e->getStatusCode() !== 409) {
                        throw $e;
                    }
                    [$status, $outcome] = [409, 'state_changed'];
                }
            }
            DB::table('incident_follow_ups')->insert(['id' => (string) Str::uuid7(), 'company_id' => $company->id, 'document_id' => $doc->id,
                'operation_id' => $operation, 'process' => $process, 'actor_id' => $actor->id, 'action' => $action, 'revision' => $revision,
                'request_key' => $key, 'outcome' => $outcome, 'http_status' => $status, 'created_at' => now()]);

            return [$status, $outcome];
        }, 3);

        return response()->json(['data' => ['outcome' => $outcome]], $status)->header('Cache-Control', 'private, no-store');
    }
}
