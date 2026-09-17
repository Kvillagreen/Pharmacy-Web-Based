<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BatchHistory extends Model
{
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
}
