<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseClaim extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'category_id',
        'description',
        'expense_date',
        'amount',
        'currency',
        'start_location',
        'end_location',
        'distance_km',
        'status',
        'policy_result_json',
        'submitted_via',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'distance_km' => 'decimal:2',
            'policy_result_json' => 'array',
        ];
    }
}
