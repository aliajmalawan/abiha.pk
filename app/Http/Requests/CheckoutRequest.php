<?php

namespace App\Http\Requests;

use App\Rules\PakistaniPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // A valid Pakistani number is stored in one shape (03XXXXXXXXX); anything else is left as typed so the rule rejects it.
        $phone = $this->input('phone');
        if (PakistaniPhone::isValid($phone)) {
            $this->merge(['phone' => PakistaniPhone::normalize($phone)]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', new PakistaniPhone],
            'email' => ['required', 'email:rfc', 'max:190'],
            'province' => ['required', Rule::in(config('abiha.provinces'))],
            'city' => ['required', 'string', 'min:2', 'max:80'],
            'address' => ['required', 'string', 'min:10', 'max:500'],
            'postal_code' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9\- ]+$/'],
            'notes' => ['nullable', 'string', 'max:500'],
            'shipping_method' => ['required', 'string', 'exists:shipping_methods,code'],
            'payment_method' => ['required', 'string'],
            'save_address' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'address.min' => 'Please enter your complete address (house, street, area).',
        ];
    }
}
