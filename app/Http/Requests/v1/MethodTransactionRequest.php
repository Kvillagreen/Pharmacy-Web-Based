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
    private const PAYMENT_METHODS = ['Cash', 'Card', 'Gcash'];

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

            'transaction_type' => ['required', Rule::in(['regular', 'controlled', 'dangerous', 'mixed'])],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'sub_total' => ['required', 'numeric', 'min:0'],
            'change' => ['required', 'numeric', 'min:0'],
            'used_amount' => ['required', 'numeric', 'min:0'],

            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'reference_number' => ['nullable', 'string', 'regex:/^\d{12}$/'],

            'discount' => ['required', 'numeric', 'min:0'],
            'discount_type' => ['nullable', Rule::in([
                'Discount',
                'SCPWD',
            ])],
            'scpwd_id_number' => ['nullable', 'string', 'max:13', 'regex:/^(?:\d{12}|OSCA-\d{8})$/'],

            'patient_name' => ['nullable', 'string', 'max:150'],
            'membership_id' => ['nullable', 'string', 'max:100'],
            'documents_submitted' => ['nullable', 'boolean'],
            'prescription' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'member_id_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'patient_age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'prescriber_name' => ['nullable', 'string', 'max:150'],
            'prescriber_prc_license_number' => ['nullable', 'string', 'regex:/^\d{7}$/'],
            'prescribed_generic_name' => ['nullable', 'string', 'max:150'],
            'prescribed_brand_name' => ['nullable', 'string', 'max:150'],
            'prescribed_dosage_strength' => ['nullable', 'string', 'max:100'],
            'prescribed_dosage_form' => ['nullable', 'string', 'max:100'],
            'prescribed_quantity_dispensed' => ['nullable', 'integer', 'min:1'],
            'dispensing_date' => ['nullable', 'date'],
            'pharmacist_signature' => ['nullable', 'string', 'max:150'],
            'customer_contact_number' => ['nullable', 'string', 'max:30'],
            'customer_id_number' => ['nullable', 'string', 'max:120'],
            'customer_address_line' => ['nullable', 'string', 'max:255'],
            'customer_barangay' => ['nullable', 'string', 'max:120'],
            'customer_city_municipality' => ['nullable', 'string', 'max:120'],
            'customer_province' => ['nullable', 'string', 'max:120'],
            'customer_postal_code' => ['nullable', 'string', 'max:20'],
            'customer_country' => ['nullable', 'string', 'max:80'],
            'prescriber_clinic_address' => ['nullable', 'string', 'max:255'],
            'prescriber_s2_license_number' => ['nullable', 'string', 'max:100'],
            'prescriber_ptr_number' => ['nullable', 'string', 'size:16', 'regex:/^PTR-\d{4}-\d{7}$/'],
            'yellow_prescription_serial_number' => ['nullable', 'string', 'size:13', 'regex:/^YP\d{11}$/'],
            'dangerous_quantity_in_words' => ['nullable', 'string', 'max:150'],
            'dangerous_quantity_in_figures' => ['nullable', 'string', 'max:100'],
            'dangerous_total_dosage' => ['nullable', 'string', 'max:150'],
            'dangerous_treatment_duration' => ['nullable', 'string', 'max:150'],
            'receiver_name' => ['nullable', 'string', 'max:150'],
            'receiver_signature' => ['nullable', 'string', 'max:150'],
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
            'payment_method.in' => 'Payment method must be Cash, Card, or Gcash.',
            'reference_number.regex' => 'Reference number must be exactly 12 digits.',
            'sub_total.required' => 'Sub Total is required.',
            'change.required' => 'Change is required.',
            'used_amount.required' => 'Used Amount is required.',
            'items.required' => 'Items are required.',
            'items.*.medicine_id.required' => 'Medicine ID is required for each item.',
            'items.*.quantity.required' => 'Quantity is required for each item.',
            'prescriber_prc_license_number.regex' => 'PRC license number must be a 7-digit code.',
            'scpwd_id_number.regex' => 'SC/PWD ID must be a 12-digit PWD number or OSCA-########.',
            'prescriber_ptr_number.size' => 'PTR number must be exactly 16 characters.',
            'prescriber_ptr_number.regex' => 'PTR number must use PTR-YYYY-####### format.',
            'yellow_prescription_serial_number.size' => 'Yellow prescription serial number must be exactly 13 characters.',
            'yellow_prescription_serial_number.regex' => 'Yellow prescription serial number must use YP followed by 11 digits.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $paymentMethod = (string) $this->input('payment_method', '');
            $referenceNumber = trim((string) $this->input('reference_number', ''));

            if (in_array($paymentMethod, ['Card', 'Gcash'], true) && $referenceNumber === '') {
                $validator->errors()->add('reference_number', 'Reference number is required for card or Gcash payments.');
            }

            if ($paymentMethod === 'Cash' && $referenceNumber !== '') {
                $validator->errors()->add('reference_number', 'Reference number is only allowed for card or Gcash payments.');
            }

            if ($this->input('discount_type') === 'SCPWD' && !filled($this->input('scpwd_id_number'))) {
                $validator->errors()->add('scpwd_id_number', 'SC/PWD ID number is required for SC/PWD discounts.');
            }

            $requirements = $this->regulatedRequirementsInPayload();
            if (!$requirements['has_controlled'] && !$requirements['has_dangerous']) {
                return;
            }

            $sharedFields = [
                'patient_name' => 'Patient full name is required for prescribed or dangerous drug transactions.',
                'customer_address_line' => 'Patient address is required for prescribed or dangerous drug transactions.',
            ];

            foreach ($sharedFields as $field => $message) {
                if (!filled($this->input($field))) {
                    $validator->errors()->add($field, $message);
                }
            }

            if ($requirements['has_controlled']) {
                $controlledFields = [
                    'patient_age' => 'Patient age is required for prescribed drug transactions.',
                    'prescriber_name' => 'Prescriber full name is required for prescribed drug transactions.',
                    'prescriber_prc_license_number' => 'PRC license number is required for prescribed drug transactions.',
                    'prescribed_generic_name' => 'Generic name is required for prescribed drug transactions.',
                    'prescribed_dosage_strength' => 'Dosage strength is required for prescribed drug transactions.',
                    'prescribed_dosage_form' => 'Dosage form is required for prescribed drug transactions.',
                    'prescribed_quantity_dispensed' => 'Quantity dispensed is required for prescribed drug transactions.',
                    'dispensing_date' => 'Dispensing date is required for prescribed drug transactions.',
                    'pharmacist_signature' => 'Pharmacist name is required for prescribed drug transactions.',
                ];

                foreach ($controlledFields as $field => $message) {
                    if (!filled($this->input($field))) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }

            if ($requirements['has_dangerous']) {
                $dangerousFields = [
                    'prescriber_name' => 'Physician full name is required for dangerous drug transactions.',
                    'prescriber_clinic_address' => 'Clinic address is required for dangerous drug transactions.',
                    'prescriber_s2_license_number' => 'S-2 license number is required for dangerous drug transactions.',
                    'prescriber_ptr_number' => 'PTR number is required for dangerous drug transactions.',
                    'yellow_prescription_serial_number' => 'Yellow prescription serial number is required for dangerous drug transactions.',
                    'dangerous_quantity_in_words' => 'Exact quantity in words is required for dangerous drug transactions.',
                    'dangerous_quantity_in_figures' => 'Exact quantity in figures is required for dangerous drug transactions.',
                    'dangerous_total_dosage' => 'Total dosage is required for dangerous drug transactions.',
                    'dangerous_treatment_duration' => 'Treatment duration is required for dangerous drug transactions.',
                    'receiver_signature' => 'Receiver signature is required for dangerous drug transactions.',
                ];

                foreach ($dangerousFields as $field => $message) {
                    if (!filled($this->input($field))) {
                        $validator->errors()->add($field, $message);
                    }
                }

                if (!filled($this->input('customer_contact_number')) && !filled($this->input('customer_id_number'))) {
                    $validator->errors()->add('customer_contact_number', 'Provide either a patient contact number or a valid ID number for dangerous drug transactions.');
                }

                if (!filled($this->input('receiver_name'))) {
                    $validator->errors()->add('receiver_name', 'Receiver name is required for dangerous drug transactions.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $paymentMethod = $this->normalizePaymentMethod($this->input('payment_method'));
        $referenceNumber = trim((string) $this->input('reference_number', ''));
        $scPwdIdNumber = strtoupper(trim((string) $this->input('scpwd_id_number', '')));
        $ptrNumber = strtoupper(trim((string) $this->input('prescriber_ptr_number', '')));
        $yellowPrescriptionSerialNumber = strtoupper(trim((string) $this->input('yellow_prescription_serial_number', '')));

        $this->merge([
            'payment_method' => $paymentMethod,
            'reference_number' => in_array($paymentMethod, ['Card', 'Gcash'], true) && $referenceNumber !== '' ? $referenceNumber : null,
            'scpwd_id_number' => $scPwdIdNumber !== '' ? $scPwdIdNumber : null,
            'prescriber_ptr_number' => $ptrNumber !== '' ? $ptrNumber : null,
            'yellow_prescription_serial_number' => $yellowPrescriptionSerialNumber !== '' ? $yellowPrescriptionSerialNumber : null,
        ]);
    }

    private function normalizePaymentMethod(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'cash' => 'Cash',
            'card' => 'Card',
            'gcash' => 'Gcash',
            default => trim((string) $value),
        };
    }

    private function regulatedRequirementsInPayload(): array
    {
        $items = collect($this->input('items', []));
        $medicineIds = $items->pluck('medicine_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($medicineIds->isEmpty()) {
            return [
                'has_controlled' => false,
                'has_dangerous' => false,
                'classification' => null,
            ];
        }

        $medicines = Medicine::query()
            ->whereIn('medicine_id', $medicineIds)
            ->get(['medicine_id', 'is_dangerous', 'needs_protection']);

        $hasDangerous = $medicines->contains(fn ($medicine) => (bool) $medicine->is_dangerous);
        $hasControlled = $medicines->contains(fn ($medicine) => (bool) $medicine->needs_protection);

        return [
            'has_controlled' => $hasControlled,
            'has_dangerous' => $hasDangerous,
            'classification' => $hasDangerous && $hasControlled
                ? 'mixed'
                : ($hasDangerous ? 'dangerous' : ($hasControlled ? 'controlled' : null)),
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
