<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class MobileUser extends Authenticatable
{
    use HasApiTokens;
    use SoftDeletes;

    protected $table = 'mobile_users';
    protected $primaryKey = 'mobile_user_id';

    protected $fillable = [
        'company_id',
        'branch_id',
        'first_name',
        'last_name',
        'email',
        'password',
        'address',
        'status',
        'login_at',
        'registered_ip',
        'last_login_ip',
        'last_seen_ip',
        'notify_transactions',
        'notify_low_stock',
        'notify_expiry_alerts',
        'notify_security_alerts',
        'notify_browser',
        'last_password_changed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'login_at' => 'datetime',
        'last_password_changed_at' => 'datetime',
        'notify_transactions' => 'boolean',
        'notify_low_stock' => 'boolean',
        'notify_expiry_alerts' => 'boolean',
        'notify_security_alerts' => 'boolean',
        'notify_browser' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'branch_id');
    }
}
