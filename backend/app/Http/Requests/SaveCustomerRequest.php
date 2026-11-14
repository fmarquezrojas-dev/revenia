<?php

namespace App\Http\Requests;

use App\Domain\Customers\RucValidator;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveCustomerRequest extends FormRequest
{
    private ?Customer $target = null;

    public function targetCustomer(): ?Customer
    {
        if ($this->route('customerId') === null) {
            return null;
        }

        return $this->target ??= Customer::query()
            ->where('company_id', $this->user()->company_id)
            ->whereKey($this->route('customerId'))
            ->firstOrFail();
    }

    public function authorize(): bool
    {
        $customer = $this->targetCustomer();

        return $customer
            ? $this->user()->can('update', $customer)
            : $this->user()->can('create', Customer::class);
    }

    protected function prepareForValidation(): void
    {
        // Documents always remain strings; never coerce JSON numbers into IDs.
        foreach (['doc_type', 'doc_number', 'name', 'address', 'email'] as $field) {
            $value = $this->input($field);
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($field === 'doc_type') {
                $value = Str::lower($value);
            }
            if ($field === 'doc_number') {
                $value = Str::upper(preg_replace('/\s+/u', ' ', $value));
            }
            $this->merge([$field => $value === '' && in_array($field, ['address', 'email'], true) ? null : $value]);
        }
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'doc_type' => [$presence, 'required', Rule::in(['ruc', 'dni', 'other'])],
            'doc_number' => [$presence, 'required', 'string', 'max:20'],
            'name' => [$presence, 'required', 'string', 'max:200'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'email' => ['sometimes', 'nullable', 'email', 'max:200'],
            'is_active' => $this->isMethod('POST') ? ['missing'] : ['sometimes', 'required', 'boolean'],
            'id' => ['missing'],
            'company_id' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $target = $this->targetCustomer();
            $type = $this->input('doc_type', $target?->doc_type);
            $number = $this->input('doc_number', $target?->doc_number);

            if ($type === 'ruc' && ! RucValidator::isValid($number)) {
                $validator->errors()->add('doc_number', 'Ingresa un RUC de 11 dígitos con prefijo y dígito verificador válidos.');
            }
            if ($type === 'dni' && ! preg_match('/\A[0-9]{8}\z/', $number)) {
                $validator->errors()->add('doc_number', 'El DNI debe tener exactamente 8 dígitos.');
            }

            $duplicate = Customer::query()
                ->where('company_id', $this->user()->company_id)
                ->where('doc_type', $type)
                ->where('doc_number', $number)
                ->when($target, fn ($query) => $query->where('id', '!=', $target->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('doc_number', 'Ya existe un cliente con este tipo y número de documento en tu empresa.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'string' => 'Ingresa un texto.',
            'max' => 'Este campo admite hasta :max caracteres.',
            'doc_type.in' => 'Selecciona RUC, DNI u otro documento.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'is_active.boolean' => 'Selecciona un estado válido.',
            'is_active.missing' => 'Los clientes nuevos se crean activos.',
            'company_id.missing' => 'La empresa se obtiene de la sesión.',
            'id.missing' => 'El identificador lo genera el servidor.',
            'created_at.missing' => 'La fecha la genera el servidor.',
            'updated_at.missing' => 'La fecha la genera el servidor.',
        ];
    }
}
