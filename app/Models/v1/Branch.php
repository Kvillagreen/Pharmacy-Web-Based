<?php
namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\v1\Company;
class Branch extends Model
{
    /** @use HasFactory<\Database\Factories\V1\BranchFactory> */
    use HasFactory;

    protected $primaryKey = 'branch_id';
    protected $keyType = 'int';
    protected $fillable = [
    'company_id',
    'branch_name',
    'branch_address',
    'branch_contact',
    'status',
];

    // Optional: default attributes
    protected $attributes = [
        'status' => 'active', // default status
    ];

    protected $public = [
        'branch_id',
        'branch_name'
    ];

    public function branches()
    {
        return $this->hasMany(Transaction::class, 'branch_id', 'branch_id');
    }



    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

}
