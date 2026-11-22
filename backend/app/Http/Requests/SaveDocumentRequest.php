<?php

namespace App\Http\Requests;

use App\Domain\Money\Quantity;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class SaveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->route('documentId') ?? $this->input('document_id');
        if ($id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                return $this->user()->can('create', Document::class);
            }
            $document = Document::where('company_id', $this->user()->company_id)->whereKey($id)->firstOrFail();

            return $this->user()->can('update', $document);
        }

        return $this->user()->can('create', Document::class);
    }

    public function rules(): array
    {
        $editing = $this->route('documentId') || $this->input('document_id');
        $rules = [
            'document_id' => $this->routeIs('documents.preview') ? ['sometimes', 'required', 'uuid'] : ['missing'],
            'version' => $editing ? ['required', 'integer', 'min:1', 'max:2147483646'] : ['missing'],
            'customer_id' => ['present', 'nullable', 'uuid'], 'series_id' => ['required', 'uuid'],
            'doc_type' => ['required', 'string', 'in:01,03'], 'currency' => ['required', 'string', 'in:PEN,USD'],
            'issue_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Lima')->toDateString()],
            'payment_terms' => ['sometimes', 'required', 'in:cash,credit'],
            'due_date' => ['nullable', 'required_if:payment_terms,credit', 'date_format:Y-m-d'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:id,product_id,quantity'],
            'items.*.id' => $editing ? ['sometimes', 'required', 'uuid', 'distinct:strict'] : ['missing'],
            'items.*.product_id' => ['required', 'uuid', 'distinct:strict'],
            'items.*.quantity' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                try {
                    if (! is_string($value)) {
                        throw new \DomainException('La cantidad debe ser texto decimal.');
                    } Quantity::parse($value);
                } catch (\DomainException $e) {
                    $fail($e->getMessage());
                }
            }],
        ];
        foreach (['company_id', 'created_by', 'status', 'number', 'issued_at', 'taxable_cents', 'exempt_cents', 'unaffected_cents', 'igv_cents', 'total_cents', 'id', 'series_code', 'created_at', 'updated_at'] as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.', 'present' => 'Envia este campo aunque sea null.',
            'missing' => 'Este campo es controlado por el servidor o no corresponde a esta operacion.',
            'in' => 'Selecciona un valor permitido.', 'uuid' => 'Selecciona una referencia valida.',
            'distinct' => 'No se permiten productos ni IDs de linea duplicados.',
            'items.max' => 'El comprobante admite hasta 50 lineas.', 'items.min' => 'Agrega al menos una linea.',
            'items.*.array' => 'Solo se permiten id, product_id y quantity por linea.',
            'due_date.required_if' => 'Indica el vencimiento de la venta a crédito.',
            'due_date.date_format' => 'Ingresa una fecha de vencimiento válida.',
            'issue_date.before_or_equal' => 'La fecha no puede ser futura en America/Lima.',
        ];
    }
}
