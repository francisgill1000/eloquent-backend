<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotPrompt extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
