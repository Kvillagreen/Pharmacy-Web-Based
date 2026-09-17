<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class SuperAdmin extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'super_admins';
    protected $primaryKey = 'super_admin_id';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'address',
        'login_at',
        'registered_ip',
        'last_login_ip',
        'last_seen_ip',
        'created_by_super_admin_id',
    ];

    protected $casts = [
        'login_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function creator()
    {
        return $this->belongsTo(self::class, 'created_by_super_admin_id', 'super_admin_id');
    }
}
