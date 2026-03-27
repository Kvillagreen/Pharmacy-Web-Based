<?php

namespace App\Models\v1;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Medicine extends Model
{

    /** @use HasFactory<\Database\Factories\V1\MedicineFactory> */
    use HasFactory;

    protected $primaryKey = 'medicine_id';
    protected $fillable = [
        "medicine_name",
        "generic_name",
        "category",
        "price",
        "reorder_level",
        "is_dangerous",
        "needs_prescriptions",
    ];
}
