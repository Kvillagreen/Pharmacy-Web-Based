<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionAttachment extends Model
{
    use HasFactory;

    protected $primaryKey = 'transaction_attachment_id';
    protected $keyType = 'int';

    protected $fillable = [
        'transaction_id',
        'uploaded_by',
        'category',
        'label',
        'remote_file_id',
        'remote_file_name',
        'original_name',
        'mime_type',
        'size_bytes',
        'status',
        'metadata',
        'uploaded_at',
        'deleted_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'uploaded_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by', 'user_id');
    }
}
