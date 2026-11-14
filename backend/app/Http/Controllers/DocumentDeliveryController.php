<?php

namespace App\Http\Controllers;

use App\Actions\Delivery\DocumentDelivery;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentDeliveryController extends Controller
{
    public function regenerate(Request $request, string $documentId, DocumentDelivery $action): JsonResponse
    {
        return $this->submit($request, $documentId, $action, 'pdf');
    }

    public function email(Request $request, string $documentId, DocumentDelivery $action): JsonResponse
    {
        return $this->submit($request, $documentId, $action, 'email');
    }

    private function submit(Request $request, string $id, DocumentDelivery $action, string $operation): JsonResponse
    {
        $doc = Document::where('company_id', $request->user()->company_id)->whereKey($id)->firstOrFail();
        Gate::authorize($operation === 'pdf' ? 'regeneratePdf' : 'sendEmail', $doc);
        $rules = ['key' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{16,100}\z/'], 'payload' => ['required', $operation === 'pdf' ? 'array:version' : 'array:file_id'], 'payload.'.($operation === 'pdf' ? 'version' : 'file_id') => $operation === 'pdf' ? 'required|integer|min:0|max:2147483646' : 'required|uuid'];
        $data = Validator::make(['key' => $request->header('Idempotency-Key'), 'payload' => $request->all()], $rules)->validate();
        if ($operation === 'pdf') {
            $data['payload']['version'] = (int) $data['payload']['version'];
        }

        return response()->json($action->request($request->user(), $id, $operation, $data['key'], $data['payload']), 202);
    }

    public function download(Request $request, string $documentId): StreamedResponse
    {
        $doc = Document::where('company_id', $request->user()->company_id)->whereKey($documentId)->firstOrFail();
        Gate::authorize('downloadPdf', $doc);
        if ($doc->status !== 'accepted') {
            DocumentDelivery::error('document_not_accepted', 'Solo se descargan comprobantes aceptados.');
        }
        $pdf = DB::table('document_pdfs')->where(['company_id' => $doc->company_id, 'document_id' => $doc->id])->first();
        $file = DocumentDelivery::file($pdf?->current_file_id, $doc->company_id, $doc->id);
        if (! $file) {
            DocumentDelivery::error($pdf?->status === 'failed' ? 'pdf_failed' : 'pdf_pending', $pdf?->status === 'failed' ? 'Fallo la generacion del PDF. Solicita revision o reintento.' : 'El PDF todavia se esta preparando.');
        }
        if (! DocumentDelivery::validFile($file)) {
            DocumentDelivery::error('pdf_missing', 'El archivo no esta disponible. Solicita regeneracion.', 404);
        }

        return Storage::disk('documents')->download($file->path, $doc->series_code.'-'.str_pad((string) $doc->number, 8, '0', STR_PAD_LEFT).'.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }
}
