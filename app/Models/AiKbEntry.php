<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiKbEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'patterns' => 'array',
        'enabled'  => 'boolean',
    ];
}
