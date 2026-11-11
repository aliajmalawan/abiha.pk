<?php

namespace App\Http\Requests;

use App\Rules\PakistaniPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');
        if (PakistaniPhone::isValid($phone)) {
            $this->merge(['phone' => PakistaniPhone::normalize($phone)]);
        }
    }

    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', new PakistaniPhone],
            'province' => ['required', Rule::in(config('abiha.provinces'))],
            'city' => ['required', 'string', 'min:2', 'max:80'],
            'address' => ['required', 'string', 'min:10', 'max:500'],
            'postal_code' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9\- ]+$/'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'address.min' => 'Please enter your complete address (house, street, area).',
        ];
    }
}
