<?php

namespace App\Http\Requests\v1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class MobileRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (!Schema::hasTable('mobile_users')) {
                        $fail('Mobile user registration is not ready yet. Please run the latest backend migration first.');
                        return;
                    }

                    $exists = \DB::table('mobile_users')->where('email', $value)->exists();
                    if ($exists) {
                        $fail('Email already exists.');
                    }
                }
            ],
            'password' => ['required', 'string', 'min:8'],
            'address' => ['nullable', 'string', 'max:255'],
            'companyId' => ['nullable', 'integer', 'exists:companies,company_id'],
            'branchId' => ['nullable', 'integer', 'exists:branches,branch_id'],
            'role' => ['nullable', Rule::in(['user'])],
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
        ], 422));
    }
}
