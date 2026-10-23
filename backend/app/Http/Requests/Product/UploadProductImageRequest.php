<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class UploadProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.mimes' => 'Format gambar tidak didukung. Hanya JPG, PNG, dan WebP yang diizinkan.',
            'image.max' => 'Ukuran gambar maksimal 2MB.',
        ];
    }
}
