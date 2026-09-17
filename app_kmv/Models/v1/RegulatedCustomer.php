<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegulatedCustomer extends Model
{
    use HasFactory;

    protected $primaryKey = 'regulated_customer_id';

    protected $fillable = [
        'full_name',
        'contact_number',
        'id_number',
        'address_line',
        'barangay',
        'city_municipality',
        'province',
        'postal_code',
        'country',
        'formatted_address',
        'last_purchase_at',
    ];

    protected $casts = [
        'last_purchase_at' => 'datetime',
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'regulated_customer_id', 'regulated_customer_id');
    }
}
