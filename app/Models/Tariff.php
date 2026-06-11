<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tariff extends Model
{
    protected $fillable = [
        'account_type',
        'display_name',
        'base_rate',
        'base_cubic_meters',
        'tiers',
        'excess_rate',
        'is_active',
    ];

    protected $casts = [
        'base_rate' => 'decimal:2',
        'base_cubic_meters' => 'integer',
        'tiers' => 'array',
        'excess_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
