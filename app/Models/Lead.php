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

    /** Editable per shop (shops.lead_opening_template); this is the fallback. */
    public const DEFAULT_OPENING = 'Hi {name}, this is {shop} 👋 We find you new customers from across the internet and handle them end-to-end — AI WhatsApp replies, one-tap calls, automatic follow-ups, and bookings, with every lead tracked in one app. Worth a quick 2-min demo?';

    /** Editable per shop (shops.lead_followup_template); this is the fallback. */
    public const DEFAULT_FOLLOWUP = 'Hi {name}, just circling back 🙂 Most businesses we set up start seeing new leads land in the first week. Happy to send a short demo whenever suits you.';

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

    /** wa.me link pre-filled with the shop's opening template ({name} → business name). */
    public function getWhatsappOpeningUrlAttribute(): ?string
    {
        return $this->draftUrl($this->shop?->lead_opening_template ?: self::DEFAULT_OPENING);
    }

    /** wa.me link pre-filled with the shop's follow-up template ({name} → business name). */
    public function getWhatsappFollowupUrlAttribute(): ?string
    {
        return $this->draftUrl($this->shop?->lead_followup_template ?: self::DEFAULT_FOLLOWUP);
    }

    /** Build wa.me/{digits}?text=... from a template, or null when there's no mobile. */
    private function draftUrl(string $template): ?string
    {
        $d = $this->normalizedDigits();
        if (! $d || ! $this->is_mobile) {
            return null;
        }
        // {name} = the lead being messaged; {shop} = the sender's own business
        // name (keeps the default tenant-safe — never hardcodes one shop).
        $text = strtr($template, [
            '{name}' => (string) $this->name,
            '{shop}' => (string) ($this->shop?->name ?? ''),
            '{category}' => (string) ($this->categoryLabel() ?? ''),
            '{area}' => (string) ($this->area() ?? ''),
        ]);
        return "https://wa.me/{$d}?text=" . rawurlencode($text);
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

    /** Human label for the lead's industry (slug -> Title Case), else null. */
    public function categoryLabel(): ?string
    {
        $c = trim((string) ($this->category ?? ''));
        if ($c === '') {
            return null;
        }
        return ucwords(str_replace(['_', '-'], ' ', $c));
    }

    /** Best available area/location string for the lead (its address), else null. */
    public function area(): ?string
    {
        $a = trim((string) ($this->address ?? ''));
        return $a !== '' ? $a : null;
    }
}
