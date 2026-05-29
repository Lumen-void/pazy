<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'permissions_json',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'permissions_json' => 'array',
            'is_system' => 'bool',
        ];
    }
}
