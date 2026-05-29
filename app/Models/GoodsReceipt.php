<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'po_id',
        'received_date',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
        ];
    }
}
