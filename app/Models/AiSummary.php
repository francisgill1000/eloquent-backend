<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored daily AI performance summary for a shop. Kept for later reference and
 * fed back to the model as recent context so consecutive days don't read alike.
 */
class AiSummary extends Model
{
    protected $table = 'ai_summaries';

    protected $fillable = [
        'shop_id', 'period_type', 'summary_date', 'period_from', 'period_to',
        'summary', 'patterns', 'recommendations', 'model',
    ];

    protected $casts = [
        'summary_date'    => 'date',
        'period_from'     => 'date',
        'period_to'       => 'date',
        'patterns'        => 'array',
        'recommendations' => 'array',
    ];
}
