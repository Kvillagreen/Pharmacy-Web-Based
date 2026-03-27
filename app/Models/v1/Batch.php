<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class Batch extends Model
{
    /** @use HasFactory<\Database\Factories\V1\BatchFactory> */
    use HasFactory;
    protected $fillable = [
        "expiry_date",
        "received_date",
        "status",

    ];
}
