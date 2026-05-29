<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'channel',
        'subject',
        'message_json',
        'status',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'message_json' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
