<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\V1\UserFactory> */
    use HasApiTokens, HasFactory;
    use SoftDeletes;
    use HasApiTokens;

    protected $primaryKey = 'user_id';


    protected $fillable = [
        'branch_id',
        'first_name',
        'last_name',
        'email',
        'password',
        'address',
        'status',
        'role',
        'login_at',
        'registered_ip',
        'last_login_ip',
        'last_seen_ip',
        'notify_transactions',
        'notify_user_registrations',
        'notify_low_stock',
        'notify_expiry_alerts',
        'notify_security_alerts',
        'notify_browser',
        'last_password_changed_at',
    ];
    protected $casts = [
        'login_at' => 'datetime',
        'last_password_changed_at' => 'datetime',
        'notify_transactions' => 'boolean',
        'notify_user_registrations' => 'boolean',
        'notify_low_stock' => 'boolean',
        'notify_expiry_alerts' => 'boolean',
        'notify_security_alerts' => 'boolean',
        'notify_browser' => 'boolean',
    ];
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function user(){
        return $this->hasMany(Transaction::class, 'user_id', 'user_id');
    }
    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'branch_id');
    }

    public function permissions()
    {
        return $this->belongsToMany(
            Permission::class,
            'user_permissions',
            'user_id',
            'permission_id'
        )->withTimestamps();
    }

    // ✅ helper function
    public function hasPermission(string $permission): bool
    {
        return $this->permissions()
            ->where('permission_name', $permission)
            ->exists();
    }

}
