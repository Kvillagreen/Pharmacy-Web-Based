<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class User extends Model
{
    use HasFactory;

    protected $table = 'tbl_user';  // point to your Hostinger table
    protected $primaryKey = 'user_id';

    public $timestamps = false;  // only if your table doesn’t have created_at / updated_at

    protected $fillable = ['first_name', 'email', 'password'];

    // Automatically hash password when creating/updating
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = Hash::make($value);
    }
}