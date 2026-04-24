<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionClaimUpdate extends Model
{
    use HasFactory;

    protected $table = 'transaction_claim_updates';
    protected $primaryKey = 'transaction_claim_update_id';

    protected $fillable = [
        'transaction_id',
        'user_id',
        'update_type',
        'title',
        'description',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
