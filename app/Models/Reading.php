<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reading extends Model
{
    protected $fillable = [
        'user_id',
        'encoder_user_id',
        'date',
        'previous_reading',
        'current_reading',
        'usage',
        'amount_due',
        'amount_paid',
        'balance',
        'payment_status',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
        'previous_reading' => 'integer',
        'current_reading' => 'integer',
        'usage' => 'integer',
        'amount_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function encoder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encoder_user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ReadingPayment::class);
    }
}
