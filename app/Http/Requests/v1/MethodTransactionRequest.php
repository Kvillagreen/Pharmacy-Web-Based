<?php

namespace App\Http\Requests\v1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
class MethodTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
{
    return [
        'user_id' => ['required', 'integer', 'exists:users,user_id'],
        'branch_id' => ['required', 'integer', 'exists:branches,branch_id'],

        'total_amount' => ['required', 'numeric', 'min:0'],
        'sub_total' => ['required', 'numeric', 'min:0'],
        'change' => ['required', 'numeric', 'min:0'],
        'used_amount' => ['required', 'numeric', 'min:0'],

        'payment_method' => ['required', 'string', 'max:50'],

        'discount' => ['required', 'numeric', 'min:0'],'discount_type' => ['nullable', Rule::in([
            'Discount',
            'SCPWD'
        ])],
        'scpwd_id_number' => ['nullable', 'string', 'max:50'],

        'request_token' => ['nullable', 'string', 'max:100'],

        'items' => ['required', 'array', 'min:1'],
        'items.*.medicine_id' => ['required', 'integer', 'exists:medicines,medicine_id'],
        'items.*.quantity' => ['required', 'integer', 'min:1'],
    ];
}
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Payment Method is required.',
            'sub_total.required' => 'Sub Total is required.',
            'change.required' => 'Change is required.',
            'used_amount.required' => 'Used Amount is required.',
            'items.required' => 'Items are required.',
            'items.*.medicine_id.required' => 'Medicine ID is required for each item.',
            'items.*.quantity.required' => 'Quantity is required for each item.',
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
