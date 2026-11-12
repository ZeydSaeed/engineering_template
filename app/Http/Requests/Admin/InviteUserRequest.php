<?php

namespace App\Http\Requests\Admin;

use App\Access\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The invitation form. The multi-step page asks for each step's fields to
 * be checked with these rules before moving on (Precognition, #6, #7); the
 * final submission checks them all again.
 */
class InviteUserRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['nullable', Rule::enum(Role::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }

        if ($this->input('role') === '') {
            $this->merge(['role' => null]);
        }
    }
}
