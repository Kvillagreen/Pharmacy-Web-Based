<?php

namespace App\Http\Requests\v1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
class MedicineRequest extends FormRequest
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
            'medicineName' => ['required', 'string'],
            'genericName' => ['required', 'string'],
            'price' => ['required', 'int'],
            'category' => ['required', 'string'],
            'reorderLevel' => ['required', 'int'],
            'isDangerous' => ['required', 'boolean'],
            'needsProtection' => ['required', 'boolean'],
        ];
    }

    // Custom messages
    public function messages(): array
    {
        return [
            'medicineName.required' => 'Medicine Name is required.',
            'genericName.required' => 'Generic Name is required.',
            'price.required' => 'Price is required.',
            'category.required' => 'Category is required.',
            'reorderLevel.required' => 'Reorder Level is required.',
            'isDangerous.required' => 'Is Dangerous is required.',
            'needsProtection.required' => 'Needs Protection is required.',
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
