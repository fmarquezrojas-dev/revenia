<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    private ?User $target = null;

    public function authorize(): bool
    {
        $actor = $this->user();

        if ($actor === null) {
            return false;
        }

        return $actor->can('update', $this->targetUser());
    }

    public function targetUser(): User
    {
        if ($this->target === null) {
            $this->target = User::query()
                ->where('company_id', $this->user()->company_id)
                ->whereKey($this->route('userId'))
                ->firstOrFail();
        }

        return $this->target;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['required', 'boolean'],

            'id' => ['missing'],
            'company_id' => ['missing'],
            'email' => ['missing'],
            'password' => ['missing'],
            'password_confirmation' => ['missing'],
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

            'role.required' => 'Selecciona un rol.',
            'role.enum' => 'El rol seleccionado no es válido.',

            'is_active.required' => 'Indica el estado del usuario.',
            'is_active.boolean' => 'El estado debe ser verdadero o falso.',

            'id.missing' => 'No se permite cambiar el identificador.',
            'company_id.missing' => 'No se permite cambiar la empresa.',
            'email.missing' => 'El correo no se modifica desde esta operación.',
            'password.missing' => 'La contraseña no se modifica desde esta operación.',
            'password_confirmation.missing' => 'La contraseña no se modifica desde esta operación.',
            'email_verified_at.missing' => 'No se permite modificar la verificación.',
            'remember_token.missing' => 'No se permite modificar este campo.',
        ];
    }
}
