<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class TransactionItem extends Model
{
    /** @use HasFactory<\Database\Factories\V1\TransactionItemFactory> */
    use HasFactory;
    protected $primaryKey = 'transaction_item_id'; // important
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'medicine_id',
        'transaction_id',
        'quantity'
    ];
    public function medicine()
    {
        return $this->belongsTo(Medicine::class, 'medicine_id', 'medicine_id');
    }
}
