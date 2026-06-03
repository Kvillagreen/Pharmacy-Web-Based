<?php

namespace App\Http\Requests\v1;

use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
class MethodMedicineRequest extends FormRequest
{
    private const MIN_REMAINING_SHELF_LIFE_MONTHS = 12;

    // Allow anyone to make this request
    public function authorize(): bool
    {
        return true;
    }

    // Validation rules
    public function rules(): array
    {
        return [
            'inventory_id' => ['nullable', 'integer', 'exists:inventories,inventory_id'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,batch_id'],
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
            'location' => ['required', 'string'],
            'mfg_date' => ['required', 'date'],
            'expiry_date' => ['required', 'date', 'after:today'],
            'received_date' => ['required', 'date', 'after_or_equal:today'],
            'branch_id' => ['required', 'int', 'min:1', 'exists:branches,branch_id'],
            /*



            'isDangerous.required' => 'Is Dangerous is required.',
            'needsProtection.required' => 'Needs Protection is required.',
            */
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $today = Carbon::today();
            $minimumExpiryDate = $today->copy()->addMonthsNoOverflow(self::MIN_REMAINING_SHELF_LIFE_MONTHS);
            $mfgDate = Carbon::parse($this->input('mfg_date'))->startOfDay();
            $receivedDate = Carbon::parse($this->input('received_date'))->startOfDay();
            $expiryDate = Carbon::parse($this->input('expiry_date'))->startOfDay();

            if ($expiryDate->lt($minimumExpiryDate)) {
                $validator->errors()->add(
                    'expiry_date',
                    'Stocks with less than ' . self::MIN_REMAINING_SHELF_LIFE_MONTHS . ' months of remaining shelf life are not accepted.'
                );
            }

            if ($mfgDate->gt($today)) {
                $validator->errors()->add('mfg_date', 'Manufacturing date cannot be in the future.');
            }

            if ($mfgDate->gte($receivedDate)) {
                $validator->errors()->add('mfg_date', 'Manufacturing date must be before the received date.');
            }

            if ($expiryDate->lte($mfgDate)) {
                $validator->errors()->add('expiry_date', 'Expiry date must be after the manufacturing date.');
            }

            if ($receivedDate->gt($expiryDate)) {
                $validator->errors()->add('received_date', 'Received date cannot be after the expiry date.');
            }

            if ($mfgDate->gt($expiryDate)) {
                $validator->errors()->add('mfg_date', 'Manufacturing date cannot be after the expiry date.');
            }
        });
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
            'expiry_date.required' => 'Expiry Date is required.',
            'expiry_date.after' => 'The system should not accept expired medicines.',
            'received_date.required' => 'Received Date is required.',
            'received_date.after_or_equal' => 'Received date must be greater than or equal to the present date.',
            'branch_id.required' => 'Branch ID is required.',
            'mfg_date.required' => 'Manufacturing Date is required.',
            'location.required' => 'Location is required.',

            // Min value messages
            'price.min' => 'Price must be greater than 0.',
            'reorder_level.min' => 'Reorder Level cannot be negative.',
            'stocks.min' => 'Stocks cannot be negative.',
            'dosage.min' => 'Dosage cannot be negative.',
            'branch_id.min' => 'Please select a valid branch.',
            'branch_id.exists' => 'Selected branch does not exist.',

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
