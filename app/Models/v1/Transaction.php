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
        'transaction_type',
        'total_amount',
        'payment_method',
        'reference_number',
        'sub_total',
        'change',
        'discount',
        'vat_amount',
        'discount_type',
        'scpwd_id_number',
        'used_amount',
        'status',
        'voided_at',
        'void_reason',
        'patient_name',
        'membership_id',
        'prescription_path',
        'prescription_file_id',
        'member_id_image_path',
        'member_id_image_file_id',
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
        'regulated_details',
    ];

    protected $appends = [
        'prescription_url',
        'member_id_image_url',
    ];

    protected $casts = [
        'documents_submitted' => 'boolean',
        'regulated_details' => 'array',
        'voided_at' => 'datetime',
    ];

     public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function items()
    {
        return $this->hasMany(TransactionItem::class, 'transaction_id', 'transaction_id');
    }

    public function attachments()
    {
        return $this->hasMany(TransactionAttachment::class, 'transaction_id', 'transaction_id');
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
        if ($this->prescription_file_id) {
            return url('/api/v1/transaction/' . $this->transaction_id . '/attachments/prescription/download');
        }

        if (!$this->prescription_path) {
            return null;
        }

        if (str_starts_with((string) $this->prescription_path, 'http')) {
            return $this->resolveDocumentUrl((string) $this->prescription_path);
        }

        $url = Storage::disk(config('transactions.documents_disk', 'public'))->url($this->prescription_path);
        return $this->resolveDocumentUrl($url);
    }

    public function getMemberIdImageUrlAttribute(): ?string
    {
        if ($this->member_id_image_file_id) {
            return url('/api/v1/transaction/' . $this->transaction_id . '/attachments/valid-id/download');
        }

        if (!$this->member_id_image_path) {
            return null;
        }

        if (str_starts_with((string) $this->member_id_image_path, 'http')) {
            return $this->resolveDocumentUrl((string) $this->member_id_image_path);
        }

        $url = Storage::disk(config('transactions.documents_disk', 'public'))->url($this->member_id_image_path);
        return $this->resolveDocumentUrl($url);
    }

    private function resolveDocumentUrl(string $path): string
    {
        if (app()->environment('production') && str_starts_with($path, 'http')) {
            return $path;
        }

        $baseUrl = app()->environment('production')
            ? rtrim(config('app.url'), '/')
            : 'http://127.0.0.1:8000';

        if (str_starts_with($path, 'http')) {
            $parsedPath = parse_url($path, PHP_URL_PATH) ?: '';
            return $baseUrl . '/' . ltrim($parsedPath, '/');
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }
}
