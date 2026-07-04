<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = ['shop_id', 'status', 'plan', 'trial_ends_at', 'access_until'];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'access_until' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function hasAccess(): bool
    {
        return $this->access_until !== null && now()->lt($this->access_until);
    }

    public function isTrialing(): bool
    {
        return $this->status === 'trialing';
    }

    public function daysLeft(): int
    {
        if ($this->access_until === null) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInDays($this->access_until, false)));
    }

    /**
     * Extend access by $days. Stacks from the current expiry if it's still in
     * the future (paying early doesn't waste remaining time); otherwise from now.
     */
    public function extend(string $plan, int $days): void
    {
        $base = ($this->access_until && $this->access_until->isFuture()) ? $this->access_until : now();
        $this->access_until = $base->copy()->addDays($days);
        $this->status = 'active';
        $this->plan = $plan;
        $this->save();
    }
}
