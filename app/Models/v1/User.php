<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\V1\UserFactory> */
    use HasApiTokens, HasFactory;

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
