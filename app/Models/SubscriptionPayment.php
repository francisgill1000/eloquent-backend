<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    protected $fillable = [
        'shop_id', 'plan', 'amount_fils', 'ziina_intent_id',
        'ziina_operation_id', 'status', 'period_days', 'paid_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
