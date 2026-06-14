<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'actor_user_id',
        'actor_name',
        'actor_role',
        'target_user_id',
        'target_account_number',
        'target_account_name',
        'module',
        'action',
        'description',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
