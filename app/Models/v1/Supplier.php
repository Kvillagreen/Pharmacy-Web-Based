<?php
namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $primaryKey = 'supplier_id'; // important
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'supplier_name',
        'supplier_first_name',
        'supplier_last_name',
        'contact_number',
        'address'
    ];

    public function batches()
    {
        return $this->hasMany(Batch::class, 'supplier_id', 'supplier_id');
    }
}
