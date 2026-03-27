<?php

namespace App\Models\v1;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    //

    /** @use HasFactory<\Database\Factories\V1\SupplierFactory> */
    use HasFactory;
    protected $fillable = [
        "first_name",
        "last_name",
        "contact_person",
        "contact_number",
        "address"
    ];
}
