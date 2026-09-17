<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsMessage extends Model
{
    use HasFactory;

    protected $primaryKey = 'sms_message_id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'reference_number',
        'template_tag',
        'direction',
        'provider_message_id',
        'provider_original_message_id',
        'user_id',
        'branch_id',
        'sender_name',
        'from_number',
        'to_number',
        'normalized_from_number',
        'normalized_to_number',
        'counterparty_number',
        'message_body',
        'provider_received_at',
        'provider_payload',
        'is_deleted',
    ];

    protected $casts = [
        'provider_received_at' => 'datetime',
        'provider_payload' => 'array',
        'is_deleted' => 'boolean',
    ];
}
