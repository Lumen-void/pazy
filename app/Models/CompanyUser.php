<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyUser extends Model
{
    use HasFactory;

    protected $table = 'company_user';

    protected $fillable = [
        'company_id',
        'user_id',
        'role_id',
        'department_id',
        'cost_center_id',
        'status',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
