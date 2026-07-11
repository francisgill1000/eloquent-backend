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

    /**
     * Mark abandoned checkouts — pending rows older than $hours — as failed.
     * Never touches paid rows (so it can't undo a granted purchase). Optionally
     * scoped to one shop. Returns the number expired.
     */
    public static function expireStale(?int $shopId = null, int $hours = 24): int
    {
        return static::query()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subHours($hours))
            ->when($shopId, fn ($q) => $q->where('shop_id', $shopId))
            ->update(['status' => 'failed']);
    }
}
