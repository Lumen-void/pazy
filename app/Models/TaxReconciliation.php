<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxReconciliation extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'invoice_id',
        'jurisdiction',
        'source_reference',
        'match_status',
        'recommendation',
        'details_json',
        'run_at',
    ];

    protected function casts(): array
    {
        return [
            'details_json' => 'array',
            'run_at' => 'datetime',
        ];
    }
}
