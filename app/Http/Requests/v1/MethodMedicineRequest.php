<?php

namespace App\Http\Requests\v1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
class MethodMedicineRequest extends FormRequest
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
            'medicine_name' => ['required', 'string'],
            'generic_name' => ['required', 'string'],
            'price' => ['required', 'numeric', 'min:1'], // price must be at least 1
            'category' => ['required', 'string'],
            'reorder_level' => ['required', 'int', 'min:0'], // ≥ 0
            'stocks' => ['required', 'int', 'min:0'],       // ≥ 0
            'dosage' => ['required', 'int', 'min:0'],       // ≥ 0
            'unit' => ['required', 'string'],
            'type' => ['required', 'string'],
            'is_dangerous' => ['required', 'boolean'],
            'needs_protection' => ['required', 'boolean'],
            'supplier_name' => ['required', 'string'],
            'supplier_first_name' => ['required', 'string'],
            'supplier_last_name' => ['required', 'string'],
            'contact_number' => ['required', 'string'],
            'address' => ['required', 'string'],
            'location' => ['required', 'string'],
            'mfg_date' => ['required', 'date'],
            'expiry_date' => ['required', 'date'],
            'received_date' => ['required', 'date'],
            'branch_id' => ['required', 'int', 'min:0'], // ≥ 0
            /*



            'isDangerous.required' => 'Is Dangerous is required.',
            'needsProtection.required' => 'Needs Protection is required.',
            */
        ];
    }

    // Custom messages
    public function messages(): array
    {
        return [
            // Required field messages
            'medicine_name.required' => 'Medicine Name is required.',
            'generic_name.required' => 'Generic Name is required.', // fixed typo
            'price.required' => 'Price is required.',
            'category.required' => 'Category is required.',
            'reorder_level.required' => 'Reorder Level is required.',
            'stocks.required' => 'Stocks is required.',
            'dosage.required' => 'Dosage is required.',
            'unit.required' => 'Unit is required.',
            'type.required' => 'Type is required.',
            'is_dangerous.required' => 'Is Dangerous is required.',
            'needs_protection.required' => 'Needs Protection is required.',
            'supplier_name.required' => 'Supplier Name is required.',
            'supplier_first_name.required' => 'Supplier First Name is required.',
            'supplier_last_name.required' => 'Supplier Last Name is required.',
            'contact_number.required' => 'Contact Number is required.',
            'address.required' => 'Address is required.',
            'expiry_date.required' => 'Expiry Date is required.',
            'received_date.required' => 'Received Date is required.',
            'branch_id.required' => 'Branch ID is required.',
            'mfg_date.required' => 'Manufacturing Date is required.',
            'location.required' => 'Location is required.',

            // Min value messages
            'price.min' => 'Price must be greater than 0.',
            'reorder_level.min' => 'Reorder Level cannot be negative.',
            'stocks.min' => 'Stocks cannot be negative.',
            'dosage.min' => 'Dosage cannot be negative.',
            'branch_id.min' => 'Branch ID cannot be negative.',

            // Type validation messages
            'medicine_name.string' => 'Medicine Name must be a string.',
            'generic_name.string' => 'Generic Name must be a string.',
            'price.numeric' => 'Price must be an number.',
            'category.string' => 'Category must be a string.',
            'reorder_level.int' => 'Reorder Level must be an integer.',
            'stocks.int' => 'Stocks must be an integer.',
            'dosage.int' => 'Dosage must be an integer.',
            'unit.string' => 'Unit must be a string.',
            'type.string' => 'Type must be a string.',
            'is_dangerous.boolean' => 'Is Dangerous must be true or false.',
            'needs_protection.boolean' => 'Needs Protection must be true or false.',
            'supplier_name.string' => 'Supplier Name must be a string.',
            'supplier_first_name.string' => 'Supplier First Name must be a string.',
            'supplier_last_name.string' => 'Supplier Last Name must be a string.',
            'contact_number.string' => 'Contact Number must be a string.',
            'address.string' => 'Address must be a string.',
            'expiry_date.date' => 'Expiry Date must be a valid date.',
            'received_date.date' => 'Received Date must be a valid date.',
            'branch_id.int' => 'Branch ID must be an integer.',
            'mfg_date.date' => 'Manufacturing Date must be a valid date.',
            'location.string' => 'Locagtion must be a string.',
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
