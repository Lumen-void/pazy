<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'gstin',
        'pan',
        'bank_account_encrypted',
        'contact_info_json',
        'compliance_score',
        'owner_user_id',
        'status',
        'kyc_status',
    ];

    protected function casts(): array
    {
        return [
            'contact_info_json' => 'array',
            'compliance_score' => 'decimal:2',
        ];
    }
}
