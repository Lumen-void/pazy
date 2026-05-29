<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntegrationWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'event',
        'idempotency_key',
        'payload_hash',
        'payload_json',
        'signature_valid',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'signature_valid' => 'bool',
            'processed_at' => 'datetime',
        ];
    }
}
