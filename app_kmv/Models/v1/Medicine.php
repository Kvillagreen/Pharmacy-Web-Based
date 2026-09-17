<?php

namespace App\Models\v1;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\v1\Inventory;
class Medicine extends Model
{

    /** @use HasFactory<\Database\Factories\V1\MedicineFactory> */
    use HasFactory;

    protected $primaryKey = 'medicine_id'; // if your table uses inventory_id
    public $incrementing = true;
    protected $keyType = 'int';
    protected $fillable = [
        "medicine_name",
        "generic_name",
        "category",
        "stocks",
        "unit",
        "dosage",
        "price",
        "type",
        "reorder_level",
        "is_dangerous",
        "is_yakap_eligible",
        "needs_protection",
        "status",
        ];
    protected $columnMap = [
    ];


    public function inventories()
    {
        return $this->hasOne(Inventory::class, 'medicine_id', 'medicine_id');
    }

    public function transactionItems()
    {
        return $this->hasMany(TransactionItem::class, 'medicine_id', 'medicine_id');
    }

}
