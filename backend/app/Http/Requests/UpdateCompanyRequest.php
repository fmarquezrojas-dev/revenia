<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $company = $user?->company;

        return $user !== null
            && $company !== null
            && $user->can('update', $company);
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'address' => ['required', 'string', 'max:300'],
            'email' => ['required', 'email', 'max:200'],

            'id' => ['missing'],
            'company_id' => ['missing'],
            'ruc' => ['missing'],
            'is_active' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return [
            'legal_name.required' => 'Ingresa la razón social.',
            'legal_name.string' => 'La razón social debe ser un texto.',
            'legal_name.max' => 'La razón social admite hasta 200 caracteres.',

            'trade_name.string' => 'El nombre comercial debe ser un texto.',
            'trade_name.max' => 'El nombre comercial admite hasta 200 caracteres.',

            'address.required' => 'Ingresa la dirección.',
            'address.string' => 'La dirección debe ser un texto.',
            'address.max' => 'La dirección admite hasta 300 caracteres.',

            'email.required' => 'Ingresa el correo de contacto.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'email.max' => 'El correo admite hasta 200 caracteres.',

            'id.missing' => 'No se permite modificar el identificador.',
            'company_id.missing' => 'No se permite seleccionar otra empresa.',
            'ruc.missing' => 'El RUC no se modifica desde este formulario.',
            'is_active.missing' => 'El estado no se modifica desde este formulario.',
        ];
    }
}
