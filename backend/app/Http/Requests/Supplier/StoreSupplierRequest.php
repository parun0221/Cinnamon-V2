<?php

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'address' => ['nullable', 'string'],
            'contact' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:160'],
        ];
    }
}
