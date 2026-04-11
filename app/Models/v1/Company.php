<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\v1\Branch;
class Company extends Model
{
    use HasFactory;
    protected $primaryKey = 'company_id';
    protected $keyType = 'int';
    protected $fillable = [
        'company_name',
        'tin_number',
        'company_email',
    ];



       public function branches()
    {
        return $this->hasMany(Branch::class, 'company_id', 'company_id');
    }
    public function company()
{
    return $this->belongsTo(Company::class, 'company_id', 'company_id');
}
}
