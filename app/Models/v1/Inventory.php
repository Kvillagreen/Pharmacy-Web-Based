<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class Inventory extends Model
{
    /** @use HasFactory<\Database\Factories\V1\InventoryFactory> */
    use HasFactory;
    protected $fillable = [
        "quantity_on_hand"
    ];
}
