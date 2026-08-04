<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One owner-assistant tool invocation, as it actually happened. Read during an
 * investigation: a turn whose reply claims a change but has no `applied` row
 * here is a change the model never made.
 */
class AssistantToolCall extends Model
{
    public const UPDATED_AT = null; // written once, never updated

    protected $fillable = [
        'shop_id', 'conversation_id', 'shop_user_id', 'tool',
        'input', 'result', 'outcome', 'user_confirmed', 'duration_ms',
    ];

    protected $casts = [
        'input' => 'array',
        'result' => 'array',
        'user_confirmed' => 'bool',
        'duration_ms' => 'int',
        'created_at' => 'datetime',
    ];
}
