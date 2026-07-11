<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Ziina-paid Business Hunt credit-pack purchase. Created 'pending', flipped
 * 'paid' by the Ziina webhook, which grants the credits (once — idempotent on
 * webhook retries via the status guard).
 */
class CreditPurchase extends Model
{
    protected $guarded = [];

    protected $casts = [
        'credits' => 'integer',
        'amount_fils' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(CreditPack::class, 'pack_id');
    }
}
