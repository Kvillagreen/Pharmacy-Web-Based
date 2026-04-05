<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MethodBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = $this->route('branch') ?? $this->route('id');

        return [
            'company_id' => ['required', 'integer', 'exists:companies,company_id'],
            'branch_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('branches', 'branch_name')
                    ->ignore($branchId, 'branch_id'),
            ],
            'branch_address' => ['required', 'string', 'max:500'],
            'branch_contact' => ['required', 'string', 'max:50'],
            'status' => ['nullable', 'string', Rule::in(['active', 'deleted', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'branch_name' => trim((string) $this->branch_name),
            'branch_address' => trim((string) $this->branch_address),
            'branch_contact' => trim((string) $this->branch_contact),
            'status' => strtolower(trim((string) $this->status)),
        ]);
    }
}
