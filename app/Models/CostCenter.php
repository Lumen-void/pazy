<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CostCenter extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = ['company_id', 'name', 'code', 'status'];
}
