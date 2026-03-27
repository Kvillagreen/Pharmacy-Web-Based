<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            "id" => $this->id,
            "firstName" => $this->first_name,
            "lastName" => $this->last_name,
            "role" => $this->role,
            "email" => $this->email,
            "branchId" => $this->branch_id,
            "status" => $this->status,
            "address" => $this->address, // make sure this column exists
        ];
    }
}
