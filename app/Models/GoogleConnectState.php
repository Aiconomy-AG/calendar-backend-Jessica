<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleConnectState extends Model
{
    protected $fillable = [
        'user_id',
        'state',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
