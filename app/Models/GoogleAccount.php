<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleAccount extends Model
{
    protected $fillable = [
        'user_id',
        'google_email',
        'access_token',
        'refresh_token',
        'expires_at',
        'calendar_id',
        'sync_token',
        'channel_id',
        'channel_token',
        'resource_id',
        'watch_expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'watch_expires_at' => 'datetime',
    ];
}
