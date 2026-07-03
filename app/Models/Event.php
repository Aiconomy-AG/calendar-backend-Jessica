<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'start',
        'end',
        'is_all_day',
        'color',
        'google_event_id',
        'google_calendar_id',
        'google_updated_at',
        'last_synced_at',
        'sync_source',
        'sync_status',
        'sync_error',
    ];

    protected $casts = [
        'start' => 'datetime',
        'end' => 'datetime',
        'is_all_day' => 'boolean',
        'google_updated_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];
}
