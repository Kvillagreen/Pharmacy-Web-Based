<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResources extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'branch_id' => (int) $this->branch_id,
            'company_id' => (int) $this->company_id,
            'branch_name' => $this->branch_name,
            'branch_address' => $this->branch_address,
            'branch_contact' => $this->branch_contact,
            'status' => $this->status,
            'branchId' => (int) $this->branch_id,
            'branchName' => $this->branch_name,
        ];
    }
}
