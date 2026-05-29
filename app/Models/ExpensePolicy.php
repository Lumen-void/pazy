<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpensePolicy extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'category_id',
        'name',
        'rules_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rules_json' => 'array',
            'is_active' => 'bool',
        ];
    }
}
