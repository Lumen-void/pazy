<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vendor_id',
        'po_id',
        'grn_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'subtotal_amount',
        'tax_amount',
        'total_amount',
        'currency',
        'extracted_data_json',
        'status',
        'file_path',
        'exception_reason',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'extracted_data_json' => 'array',
            'subtotal_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class, 'grn_id');
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
