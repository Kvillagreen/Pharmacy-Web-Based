<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Model;

class SmsOrderItem extends Model
{
    protected $primaryKey = 'sms_order_item_id';

    protected $fillable = [
        'sms_order_id',
        'medicine_id',
        'quantity',
        'unit_price',
        'line_total',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function medicine()
    {
        return $this->belongsTo(Medicine::class, 'medicine_id', 'medicine_id');
    }
}
