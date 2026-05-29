<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'document_type',
        'file_path',
        'verified',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'bool',
            'metadata_json' => 'array',
        ];
    }
}
