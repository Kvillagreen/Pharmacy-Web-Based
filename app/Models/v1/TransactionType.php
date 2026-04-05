<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionType extends Model
{
    //
    /** @use HasFactory<\Database\Factories\V1\TransactionFactory> */
    use HasFactory;

    protected $primaryKey = 'transaction_type_id'; // important
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        "transaction_type_name",
        "customer_full_name",
        "customer_id_number",
        "coverage_type"
        ];

    public function transaction(){
        return $this->belongsTo(Transaction::class);
    }
}
