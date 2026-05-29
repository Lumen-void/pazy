<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'entity_type',
        'entity_id',
        'payee_type',
        'payee_id',
        'company_account_id',
        'amount',
        'currency',
        'mode',
        'status',
        'utr_number',
        'idempotency_key',
        'initiated_by',
        'approved_by',
        'initiated_at',
        'completed_at',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'initiated_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }
}
