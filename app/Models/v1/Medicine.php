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
        "pricing_type",
        "cost_price",
        "markup_percent",
        "stocks",
        "unit",
        "units_per_box",
        "dosage",
        "price",
        "type",
        "reorder_level",
        "is_dangerous",
        "is_yakap_eligible",
        "needs_protection",
<<<<<<< HEAD
        "status",
=======
        "archived_at",
>>>>>>> f828ce2 (Add BIR 2306 records, SMS orders, batch history, inventory revisions)
        ];
    protected $casts = [
        'archived_at' => 'datetime',
        'cost_price' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'price' => 'decimal:2',
    ];
    protected $columnMap = [
    ];


    public function inventories()
    {
        return $this->hasOne(Inventory::class, 'medicine_id', 'medicine_id');
    }

    public function batches()
    {
        return $this->hasManyThrough(Batch::class, Inventory::class, 'medicine_id', 'batch_id', 'medicine_id', 'batch_id');
    }

    public function transactionItems()
    {
        return $this->hasMany(TransactionItem::class, 'medicine_id', 'medicine_id');
    }

}
