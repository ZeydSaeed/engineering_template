<?php

namespace App\Modules\Requests\Http;

use App\Modules\Requests\Domain\Decision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An approver's decision: approve, or reject with a reason (ADR-0033).
 */
class DecideRequestRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(Decision::class)],
            'note' => ['nullable', 'required_if:decision,reject', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['note' => __('reason')];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['note.required_if' => __('Say why the request is rejected.')];
    }

    public function decision(): Decision
    {
        return Decision::from((string) $this->string('decision'));
    }
}
