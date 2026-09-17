<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Model;

class SmsOrder extends Model
{
    protected $primaryKey = 'sms_order_id';

    protected $fillable = [
        'branch_id',
        'customer_number',
        'message_body',
        'provider_message_id',
        'status',
        'total_price',
    ];

    protected $casts = [
        'total_price' => 'decimal:2',
    ];

    public function items()
    {
        return $this->hasMany(SmsOrderItem::class, 'sms_order_id', 'sms_order_id');
    }
}
