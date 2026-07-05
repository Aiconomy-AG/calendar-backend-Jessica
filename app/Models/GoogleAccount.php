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
        'sync_status',
        'sync_error',
        'last_webhook_at',
        'last_sync_attempt_at',
        'last_successful_sync_at',
        'channel_id',
        'channel_token',
        'resource_id',
        'watch_expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'watch_expires_at' => 'datetime',
        'last_webhook_at' => 'datetime',
        'last_sync_attempt_at' => 'datetime',
        'last_successful_sync_at' => 'datetime',
    ];
}
