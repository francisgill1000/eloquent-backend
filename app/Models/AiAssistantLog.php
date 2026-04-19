<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAssistantLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'matched'  => 'boolean',
        'reviewed' => 'boolean',
        'lat'      => 'float',
        'lon'      => 'float',
    ];
}
