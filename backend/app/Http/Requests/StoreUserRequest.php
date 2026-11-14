<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge([
                'email' => Str::lower(trim($email)),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'email' => [
                'required',
                'string',
                'email',
                'max:200',
                Rule::unique('users', 'email'),
            ],

            'password' => [
                'required',
                'string',
                'max:72',
                'confirmed',
                Password::min(12)
                    ->letters()
                    ->mixedCase()
                    ->numbers(),
                function (
                    string $attribute,
                    mixed $value,
                    \Closure $fail,
                ): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail(
                            'La contraseña no puede superar 72 bytes.'
                        );
                    }
                },
            ],

            'role' => [
                'required',
                Rule::enum(UserRole::class),
            ],

            'id' => ['missing'],
            'company_id' => ['missing'],
            'is_active' => ['missing'],
            'email_verified_at' => ['missing'],
            'remember_token' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Ingresa el nombre del usuario.',
            'name.string' => 'El nombre debe ser un texto.',
            'name.max' => 'El nombre admite hasta 150 caracteres.',

            'email.required' => 'Ingresa el correo del usuario.',
            'email.email' => 'Ingresa un correo válido.',
            'email.max' => 'El correo admite hasta 200 caracteres.',
            'email.unique' => 'El correo ya está registrado.',

            'password.required' => 'Ingresa una contraseña.',
            'password.max' => 'La contraseña admite hasta 72 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 12 caracteres.',
            'password.letters' => 'La contraseña debe incluir letras.',
            'password.mixed' => 'Incluye mayúsculas y minúsculas.',
            'password.numbers' => 'La contraseña debe incluir números.',

            'role.required' => 'Selecciona un rol.',
            'role.enum' => 'El rol seleccionado no es válido.',

            'id.missing' => 'El identificador lo genera el servidor.',
            'company_id.missing' => 'La empresa se obtiene de la sesión.',
            'is_active.missing' => 'Las cuentas nuevas se crean activas.',
            'email_verified_at.missing' => 'No se permite asignar la verificación.',
            'remember_token.missing' => 'No se permite asignar este campo.',
        ];
    }
}
