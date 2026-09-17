<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

class TransactionAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'label' => ['nullable', 'string', 'max:120'],
            'prescriber_name' => ['nullable', 'string', 'max:150'],
            'prescription_reference' => ['nullable', 'string', 'max:120'],
            'authorization_reference' => ['nullable', 'string', 'max:120'],
            'drug_name' => ['nullable', 'string', 'max:150'],
            'quantity' => ['nullable', 'regex:/^\d+(?:\.\d+)?$/'],
            'unit' => ['nullable', 'string', 'max:40'],
        ];

        if ($this->isMethod('post')) {
            $rules['file'] = ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480'];
        }

        return $rules;
    }
}
