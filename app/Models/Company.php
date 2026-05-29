<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'legal_name',
        'code',
        'base_currency',
        'timezone',
        'status',
        'tax_profile_json',
    ];

    protected function casts(): array
    {
        return [
            'tax_profile_json' => 'array',
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
