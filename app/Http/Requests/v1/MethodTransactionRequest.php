<?php

namespace App\Http\Requests\v1;

use App\Models\v1\Medicine;
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

            'transaction_type' => ['required', Rule::in(['regular'])],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'sub_total' => ['required', 'numeric', 'min:0'],
            'change' => ['required', 'numeric', 'min:0'],
            'used_amount' => ['required', 'numeric', 'min:0'],

            'payment_method' => ['required', 'string', 'max:50'],

            'discount' => ['required', 'numeric', 'min:0'],
            'discount_type' => ['nullable', Rule::in([
                'Discount',
                'SCPWD',
            ])],
            'scpwd_id_number' => ['nullable', 'string', 'max:50'],

            'patient_name' => ['nullable', 'string', 'max:150'],
            'membership_id' => ['nullable', 'string', 'max:100'],
            'documents_submitted' => ['nullable', 'boolean'],
            'prescription' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'member_id_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'customer_contact_number' => ['nullable', 'string', 'max:30'],
            'customer_id_number' => ['nullable', 'string', 'max:120'],
            'customer_address_line' => ['nullable', 'string', 'max:255'],
            'customer_barangay' => ['nullable', 'string', 'max:120'],
            'customer_city_municipality' => ['nullable', 'string', 'max:120'],
            'customer_province' => ['nullable', 'string', 'max:120'],
            'customer_postal_code' => ['nullable', 'string', 'max:20'],
            'customer_country' => ['nullable', 'string', 'max:80'],
            'request_token' => ['nullable', 'string', 'max:100'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.medicine_id' => ['required', 'integer', 'exists:medicines,medicine_id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
    public function messages(): array
    {
        return [
            'transaction_type.required' => 'Transaction type is required.',
            'payment_method.required' => 'Payment Method is required.',
            'sub_total.required' => 'Sub Total is required.',
            'change.required' => 'Change is required.',
            'used_amount.required' => 'Used Amount is required.',
            'items.required' => 'Items are required.',
            'items.*.medicine_id.required' => 'Medicine ID is required for each item.',
            'items.*.quantity.required' => 'Quantity is required for each item.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->hasRegulatedMedicineInPayload()) {
                return;
            }

            $requiredFields = [
                'patient_name' => 'Customer name is required for controlled or dangerous medicine purchases.',
                'customer_contact_number' => 'Customer contact number is required for controlled or dangerous medicine purchases.',
                'customer_address_line' => 'Address line is required for controlled or dangerous medicine purchases.',
                'customer_barangay' => 'Barangay is required for controlled or dangerous medicine purchases.',
                'customer_city_municipality' => 'City / Municipality is required for controlled or dangerous medicine purchases.',
                'customer_province' => 'Province is required for controlled or dangerous medicine purchases.',
                'customer_country' => 'Country is required for controlled or dangerous medicine purchases.',
            ];

            foreach ($requiredFields as $field => $message) {
                if (!filled($this->input($field))) {
                    $validator->errors()->add($field, $message);
                }
            }

            if (!$this->hasFile('prescription')) {
                $validator->errors()->add('prescription', 'Prescription document is required for controlled or dangerous medicine purchases.');
            }

            if (!$this->hasFile('member_id_image')) {
                $validator->errors()->add('member_id_image', 'Valid ID document is required for controlled or dangerous medicine purchases.');
            }
        });
    }

    private function hasRegulatedMedicineInPayload(): bool
    {
        $items = collect($this->input('items', []));
        $medicineIds = $items->pluck('medicine_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($medicineIds->isEmpty()) {
            return false;
        }

        return Medicine::query()
            ->whereIn('medicine_id', $medicineIds)
            ->where(function ($query) {
                $query->where('is_dangerous', true)
                    ->orWhere('needs_protection', true);
            })
            ->exists();
    }

protected function failedValidation(Validator $validator)
{
    throw new HttpResponseException(response()->json([
        'success' => false,
        'message' => $validator->errors()->first() // first error message
    ]));
}

}
