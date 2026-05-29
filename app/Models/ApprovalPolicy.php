<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovalPolicy extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'entity_type',
        'priority',
        'steps_json',
        'conditions_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'steps_json' => 'array',
            'conditions_json' => 'array',
            'is_active' => 'bool',
        ];
    }
}
