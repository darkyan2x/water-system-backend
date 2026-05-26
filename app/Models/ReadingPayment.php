<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingPayment extends Model
{
    protected $fillable = [
        'reading_id',
        'user_id',
        'teller_user_id',
        'amount',
        'payment_date',
        'or_number',
        'payment_method',
        'remarks',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function reading(): BelongsTo
    {
        return $this->belongsTo(Reading::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function teller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teller_user_id');
    }
}