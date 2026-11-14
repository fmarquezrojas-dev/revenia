<?php

namespace App\Http\Requests;

use App\Domain\Series\SeriesCode;
use App\Models\DocumentSeries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDocumentSeriesRequest extends FormRequest
{
    private ?DocumentSeries $target = null;

    public function targetSeries(): ?DocumentSeries
    {
        if ($this->route('seriesId') === null) {
            return null;
        }

        return $this->target ??= DocumentSeries::query()->where('company_id', $this->user()->company_id)
            ->whereKey($this->route('seriesId'))->firstOrFail();
    }

    public function authorize(): bool
    {
        $series = $this->targetSeries();

        return $series ? $this->user()->can('update', $series) : $this->user()->can('create', DocumentSeries::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('POST') && is_string($this->input('code'))) {
            $this->merge(['code' => SeriesCode::normalize($this->input('code'))]);
        }
    }

    public function rules(): array
    {
        $rules = array_fill_keys(['id', 'company_id', 'current_number', 'created_at', 'updated_at'], ['missing']);
        if ($this->isMethod('PATCH')) {
            return $rules + ['doc_type' => ['missing'], 'code' => ['missing'], 'is_active' => ['required', 'boolean']];
        }

        return $rules + [
            'is_active' => ['missing'],
            'doc_type' => ['required', 'string', Rule::in(['01', '03', '07'])],
            'code' => ['bail', 'required', 'string', 'size:4', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($this->input('doc_type')) || ! SeriesCode::isValid($this->input('doc_type'), $value)) {
                    $fail('Usa cuatro caracteres ASCII: F para factura, B para boleta y F o B para nota de credito.');
                }
            }, Rule::unique('document_series', 'code')->where(fn ($query) => $query
                ->where('company_id', $this->user()->company_id)->where('doc_type', $this->input('doc_type')))],
        ];
    }

    public function messages(): array
    {
        return ['required' => 'Este campo es obligatorio.', 'code.size' => 'La serie debe tener cuatro caracteres.',
            'doc_type.in' => 'Selecciona factura, boleta o nota de credito.',
            'code.unique' => 'Ya existe esta serie para el tipo de comprobante en tu empresa.',
            'missing' => 'Este campo lo fija el servidor y no se puede modificar.',
            'is_active.boolean' => 'Selecciona un estado valido.'];
    }
}
