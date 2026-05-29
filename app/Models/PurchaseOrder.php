<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vendor_id',
        'requester_user_id',
        'po_number',
        'issued_at',
        'total_amount',
        'currency',
        'status',
        'erp_sync_status',
        'terms_json',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'terms_json' => 'array',
            'total_amount' => 'decimal:2',
        ];
    }

    public function items()
    {
        return $this->hasMany(PoItem::class, 'po_id');
    }
}
