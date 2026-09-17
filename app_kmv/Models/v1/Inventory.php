<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    use HasFactory;

    protected $primaryKey = 'inventory_id'; // if your table uses inventory_id
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'branch_id',
        'medicine_id',
        'batch_id',
        'stocks',
        'container_type',
        'container_name',
        'container_count',
        'pcs_per_container',
    ];

     public function medicine()
    {
        return $this->belongsTo(Medicine::class, 'medicine_id', 'medicine_id');
    }

    public function batch()
    {
        return $this->belongsTo(Batch::class, 'batch_id', 'batch_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'branch_id');
    }

}
