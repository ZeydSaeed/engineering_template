<?php

namespace App\Modules\Requests\Http;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A request's fields, for creating it and for editing its draft; checked
 * while typing (Precognition). Editing also sends the lock version the form
 * was opened with (ADR-0033).
 */
class SaveRequestRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'details' => ['required', 'string', 'max:5000'],
            // numeric(12,2): at most 10 digits before the point, 2 after.
            'amount' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
        ];

        if ($this->isMethod('PUT')) {
            $rules['lock_version'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => __('title'),
            'details' => __('details'),
            'amount' => __('amount'),
        ];
    }

    /**
     * @return array{title: string, details: string, amount: string|null}
     */
    public function fields(): array
    {
        $amount = $this->input('amount');

        return [
            'title' => (string) $this->string('title'),
            'details' => (string) $this->string('details'),
            'amount' => $amount === null || $amount === '' ? null : (string) $amount,
        ];
    }
}
