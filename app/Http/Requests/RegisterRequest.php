<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** One person, one account: "Sana@Example.com" and "sana@example.com" are the same address, on any database. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'regex:/^[\d+\-\s()]{7,20}$/'],
            'password' => ['required', 'confirmed', 'max:72', Password::min(8)->letters()->numbers()],   // bcrypt only reads the first 72 bytes
        ];
    }
}
