<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    private ?Product $target = null;

    public function targetProduct(): ?Product
    {
        if ($this->route('productId') === null) {
            return null;
        }

        return $this->target ??= Product::query()->where('company_id', $this->user()->company_id)
            ->whereKey($this->route('productId'))->firstOrFail();
    }

    public function authorize(): bool
    {
        $product = $this->targetProduct();

        return $product ? $this->user()->can('update', $product) : $this->user()->can('create', Product::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Str::upper(trim($this->input('code')))]);
        }
        if (is_string($this->input('description'))) {
            $this->merge(['description' => trim($this->input('description'))]);
        }
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $unique = Rule::unique('products', 'code')->where('company_id', $this->user()->company_id);
        if ($this->targetProduct()) {
            $unique->ignore($this->targetProduct()->id);
        }

        return [
            'code' => [$presence, 'required', 'string', 'max:30', $unique],
            'description' => [$presence, 'required', 'string', 'max:250'],
            'unit_code' => [$presence, 'required', Rule::in(['NIU', 'ZZ'])],
            'currency' => [$presence, 'required', Rule::in(['PEN', 'USD'])],
            'tax_type' => [$presence, 'required', 'string', Rule::in(['10', '20', '30'])],
            'unit_price_cents' => [$presence, 'required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value) || $value < 0 || $value > Product::MAX_PRICE_CENTS) {
                    $fail('El precio debe ser un entero de céntimos entre 0 y '.Product::MAX_PRICE_CENTS.'.');
                }
            }],
            'is_active' => $this->isMethod('POST') ? ['missing'] : ['sometimes', 'required', 'boolean'],
            'company_id' => ['missing'], 'id' => ['missing'], 'created_at' => ['missing'], 'updated_at' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.', 'string' => 'Ingresa un texto.',
            'code.unique' => 'Ya existe un producto con este código en tu empresa.',
            'code.max' => 'El código admite hasta 30 caracteres.',
            'description.max' => 'La descripción admite hasta 250 caracteres.',
            'in' => 'Selecciona un valor permitido.',
            'company_id.missing' => 'La empresa se obtiene de la sesión.',
            'is_active.missing' => 'Los productos nuevos se crean activos.',
        ];
    }
}
