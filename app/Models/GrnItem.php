<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GrnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'grn_id',
        'po_item_id',
        'quantity_received',
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:4',
        ];
    }
}
