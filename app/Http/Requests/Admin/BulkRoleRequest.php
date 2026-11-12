<?php

namespace App\Http\Requests\Admin;

use App\Access\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkRoleRequest extends FormRequest
{
    public const MAX_USERS = 100;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['assign', 'revoke'])],
            'role' => ['required', Rule::enum(Role::class)],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_USERS],
            'ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }
}
