<?php

namespace App\Models\v1;

<<<<<<< HEAD
use Illuminate\Database\Eloquent\Factories\HasFactory;
=======
>>>>>>> f828ce2 (Add BIR 2306 records, SMS orders, batch history, inventory revisions)
use Illuminate\Database\Eloquent\Model;

class BatchHistory extends Model
{
<<<<<<< HEAD
    use HasFactory;

    protected $primaryKey = 'batch_history_id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'batch_id',
        'user_id',
        'action',
        'notes',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
=======
    protected $primaryKey = 'batch_history_id';

    protected $fillable = [
        'batch_id', 'medicine_id', 'inventory_id', 'branch_id', 'user_id',
        'action', 'quantity_change', 'stock_after', 'notes', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];
>>>>>>> f828ce2 (Add BIR 2306 records, SMS orders, batch history, inventory revisions)
}
