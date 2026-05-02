<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Transaction extends Model
{

    /** @use HasFactory<\Database\Factories\V1\TransactionFactory> */
    use HasFactory;

    protected $primaryKey = 'transaction_id'; // important
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'user_id',
        'branch_id',
        'transaction_type_id',
        'transaction_type',
        'total_amount',
        'payment_method',
        'sub_total',
        'change',
        'discount',
        'discount_type',
        'scpwd_id_number',
        'used_amount',
        'patient_name',
        'membership_id',
        'prescription_path',
        'member_id_image_path',
        'documents_submitted',
        'regulated_customer_id',
        'customer_contact_number',
        'customer_id_number',
        'customer_address_line',
        'customer_barangay',
        'customer_city_municipality',
        'customer_province',
        'customer_postal_code',
        'customer_country',
        'customer_formatted_address',
        'regulated_classification',
    ];

    protected $appends = [
        'prescription_url',
        'member_id_image_url',
    ];

    protected $casts = [
        'documents_submitted' => 'boolean',
    ];

    public function transactions()
    {
        return $this->belongsTo(TransactionType::class, 'transaction_type_id', 'transaction_type_id');
    }

     public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function transaction_type()
    {
        return $this->belongsTo(TransactionType::class, 'transaction_type_id', 'transaction_type_id');
        }
    public function items()
    {
        return $this->hasMany(TransactionItem::class, 'transaction_id', 'transaction_id');
    }
    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'branch_id');
    }

    public function regulatedCustomer()
    {
        return $this->belongsTo(RegulatedCustomer::class, 'regulated_customer_id', 'regulated_customer_id');
    }

    public function getPrescriptionUrlAttribute(): ?string
    {
        if (!$this->prescription_path) {
            return null;
        }

        return Storage::disk(config('transactions.documents_disk', 'public'))->url($this->prescription_path);
    }

    public function getMemberIdImageUrlAttribute(): ?string
    {
        if (!$this->member_id_image_path) {
            return null;
        }

        return Storage::disk(config('transactions.documents_disk', 'public'))->url($this->member_id_image_path);
    }
}
