<?php
namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Batch extends Model
{
    use HasFactory;

    protected $primaryKey = 'batch_id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'medicine_id',
        'batch_number',
        'expiry_date',
        'received_date',
        'mfg_date',
        'location',
        'status',
    ];

    public function inventories()
    {
        return $this->hasMany(Inventory::class, 'batch_id', 'batch_id');
    }

}
