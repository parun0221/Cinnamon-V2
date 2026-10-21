<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            // The route parameter is the User being updated, so the unique
            // check must ignore that row instead of the requester's own.
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'role' => ['sometimes', 'required', Rule::in(['admin', 'karyawan'])],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * Normalise `is_active` to a real boolean.
     *
     * Laravel's `boolean` rule accepts true, false, 1, 0, "1", and "0", but
     * it does not convert them: `validated()` hands back the string "0" for a
     * request that sent "0". A guard written as `=== false` would then miss
     * the very value it exists to catch.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('is_active') && in_array($this->input('is_active'), [true, false, 1, 0, '1', '0'], true)) {
            $this->merge(['is_active' => (bool) $this->input('is_active')]);
        }
    }
}
