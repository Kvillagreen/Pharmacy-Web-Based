<?php
namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    /** @use HasFactory<\Database\Factories\V1\BranchFactory> */
    use HasFactory;

    protected $primaryKey = 'branch_id';
    protected $keyType = 'int';
    protected $fillable = [
        'branch_name',
        'branch_address',
        'branch_contact',
        'status',
    ];

    // Optional: default attributes
    protected $attributes = [
        'status' => 'pending', // default status
    ];

    protected $public = [
        'branch_id',
        'branch_name'
    ];
}
