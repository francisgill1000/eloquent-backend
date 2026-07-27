<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    use HasFactory;

    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_NOTE = 'note';
    public const TYPE_CONTACTED = 'contacted';
    public const TYPE_ASSIGNED = 'assigned';

    /**
     * How a touch happened. Fixed and opinionated, like Lead::STATUSES —
     * deliberately not user-configurable, so reports can never fragment across
     * three spellings of "Instagram".
     */
    public const CHANNELS = [
        'whatsapp', 'instagram', 'facebook', 'tiktok', 'linkedin',
        'phone', 'email', 'walk_in', 'other',
    ];

    /** Who reached out: `out` = we contacted them, `in` = they contacted us. */
    public const DIRECTION_OUT = 'out';
    public const DIRECTION_IN = 'in';
    public const DIRECTIONS = [self::DIRECTION_OUT, self::DIRECTION_IN];

    protected $fillable = [
        'lead_id',
        'type',
        'channel',
        'direction',
        'payload',
        'user_id',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
