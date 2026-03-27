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
}
