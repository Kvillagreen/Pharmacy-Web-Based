<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

    use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
class LoginRequest extends  FormRequest
{
    // Allow anyone to make this request
    public function authorize(): bool
    {
        return true;
    }

    // Validation rules
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    // Custom messages
    public function messages(): array
    {
        return [
            'email.required' => 'Email is required.',
            'email.email' => 'Email is invalid.',
            'password.required' => 'Password is required.',
            'password.string' => 'Password must be valid.',
        ];

    }

protected function failedValidation(Validator $validator)
{
    throw new HttpResponseException(response()->json([
        'success' => false,
        'message' => $validator->errors()->first() // first error message
    ]));
}
}
