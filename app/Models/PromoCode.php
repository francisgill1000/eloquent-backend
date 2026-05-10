<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'code',
        'label',
        'discount_type',
        'discount_value',
        'valid_from',
        'valid_until',
        'max_uses',
        'uses_count',
        'is_active',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'discount_value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(MarketingCampaign::class);
    }

    /**
     * Returns the discounted total for a given subtotal, or null if the code is not redeemable.
     */
    public function applyTo(float $subtotal): ?array
    {
        if (! $this->isRedeemable()) {
            return null;
        }
        $discount = $this->discount_type === 'percent'
            ? round($subtotal * ((float) $this->discount_value) / 100, 2)
            : min((float) $this->discount_value, $subtotal);
        $discount = max(0, min($discount, $subtotal));
        return [
            'subtotal' => round($subtotal, 2),
            'discount' => $discount,
            'total'    => round($subtotal - $discount, 2),
        ];
    }

    public function isRedeemable(): bool
    {
        if (! $this->is_active) return false;
        $today = now()->toDateString();
        if ($this->valid_from && $today < $this->valid_from->toDateString()) return false;
        if ($this->valid_until && $today > $this->valid_until->toDateString()) return false;
        if ($this->max_uses !== null && $this->uses_count >= $this->max_uses) return false;
        return true;
    }
}
