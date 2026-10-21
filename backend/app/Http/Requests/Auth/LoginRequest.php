<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Credentials for the auth guard. `remember` is deliberately excluded —
     * it is a session option, not a user attribute.
     *
     * @return array<string, string>
     */
    public function credentials(): array
    {
        return $this->safe()->only(['email', 'password']);
    }
}
