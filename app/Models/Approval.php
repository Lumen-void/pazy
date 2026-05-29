<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'entity_type',
        'entity_id',
        'policy_id',
        'approver_id',
        'requested_by',
        'level',
        'status',
        'decision_notes',
        'approved_at',
        'rejected_at',
        'context_json',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'context_json' => 'array',
        ];
    }
}
