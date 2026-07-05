<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasFactory;

    /** The fixed, opinionated funnel — deliberately not user-configurable. */
    public const STATUSES = ['new', 'sent', 'replied', 'demo', 'won', 'pass'];

    protected $fillable = [
        'shop_id',
        'name',
        'phone',
        'whatsapp',
        'website',
        'address',
        'category',
        'lat',
        'lng',
        'source',
        'external_ref',
        'status',
        'notes',
        'last_contacted_at',
        'next_followup_at',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'last_contacted_at' => 'datetime',
        'next_followup_at' => 'datetime',
    ];

    protected $appends = ['whatsapp_url', 'is_mobile', 'tel_url', 'map_url'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    /** Tenant scope — every lead query must go through the current shop. */
    public function scopeForShop(Builder $query, int $shopId): Builder
    {
        return $query->where('shop_id', $shopId);
    }

    // --- Enrichment -------------------------------------------------------

    /**
     * Best contact number normalized to UAE international digits (no '+').
     * Handles: already-971, 00-prefixed, 0-local (e.g. 050…), and bare 5X…
     */
    public function normalizedDigits(): ?string
    {
        $raw = $this->whatsapp ?: $this->phone;
        if (! $raw) {
            return null;
        }

        $d = preg_replace('/\D+/', '', $raw); // strip non-digits
        if ($d === '') {
            return null;
        }

        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);              // 00971… -> 971…
        }
        if (str_starts_with($d, '971')) {
            return $d;
        }
        if (str_starts_with($d, '0')) {
            return '971' . ltrim($d, '0');   // 050… -> 97150…
        }
        if (str_starts_with($d, '5') && strlen($d) === 9) {
            return '971' . $d;               // 50xxxxxxx -> 97150xxxxxxx
        }

        return $d; // best effort for non-UAE / already-clean numbers
    }

    /** UAE mobile? (971 5X XXXXXXX = 12 digits). WhatsApp only valid if true. */
    public function getIsMobileAttribute(): bool
    {
        $d = $this->normalizedDigits();
        return $d !== null && str_starts_with($d, '9715') && strlen($d) === 12;
    }

    public function getWhatsappUrlAttribute(): ?string
    {
        $d = $this->normalizedDigits();
        return $d ? "https://wa.me/{$d}" : null;
    }

    public function getTelUrlAttribute(): ?string
    {
        $d = $this->normalizedDigits();
        return $d ? "tel:+{$d}" : null;
    }

    public function getMapUrlAttribute(): ?string
    {
        if ($this->lat === null || $this->lng === null) {
            return null;
        }
        return "https://www.google.com/maps/search/?api=1&query={$this->lat},{$this->lng}";
    }
}
