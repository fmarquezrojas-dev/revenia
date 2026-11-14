<?php

namespace App\Http\Controllers;

use App\Actions\Issuance\RequestIssuance;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class IssuanceController extends Controller
{
    public function issue(Request $request, string $documentId, RequestIssuance $action): JsonResponse
    {
        return $this->submit($request, $documentId, $action, 'issue');
    }

    public function retry(Request $request, string $documentId, RequestIssuance $action): JsonResponse
    {
        return $this->submit($request, $documentId, $action, 'retry');
    }

    private function submit(Request $request, string $id, RequestIssuance $action, string $operation): JsonResponse
    {
        $doc = Document::where('company_id', $request->user()->company_id)->whereKey($id)->firstOrFail();
        Gate::authorize('update', $doc);
        $input = ['payload' => $request->all(), 'key' => $request->header('Idempotency-Key')];
        $data = Validator::make($input, ['payload' => 'required|array:version', 'payload.version' => 'required|integer|min:1|max:2147483646', 'key' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{16,100}\z/']])->validate();

        return response()->json($action->handle($request->user(), $id, (int) $data['payload']['version'], $data['key'], $operation), 202);
    }

    public function events(Request $request, string $documentId): JsonResponse
    {
        $doc = Document::where('company_id', $request->user()->company_id)->whereKey($documentId)->firstOrFail();
        Gate::authorize('view', $doc);

        return response()->json(['data' => DB::table('document_events')->where('company_id', $doc->company_id)->where('document_id', $doc->id)->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get(['id', 'type', 'from_status', 'to_status', 'actor_id', 'attempt_id', 'code', 'message', 'created_at'])]);
    }
}
