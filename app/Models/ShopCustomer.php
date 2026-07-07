<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopCustomer extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'name',
        'whatsapp',
        'whatsapp_normalized',
        'notes',
        'preferences',
    ];

    protected $casts = [
        'preferences' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public static function normalize(?string $whatsapp): string
    {
        return preg_replace('/\D+/', '', (string) $whatsapp);
    }

    /**
     * Find an existing shop customer by WhatsApp tail (last 9 digits) or create a new one.
     * If a row is found and its name is blank, fill it in from $name.
     */
    public static function findOrCreateForShop(int $shopId, ?string $whatsapp, ?string $name): ?self
    {
        $normalized = self::normalize($whatsapp);
        if ($normalized === '') return null;

        $tail = strlen($normalized) > 9 ? substr($normalized, -9) : $normalized;

        $existing = self::where('shop_id', $shopId)
            ->where('whatsapp_normalized', 'LIKE', '%' . $tail)
            ->first();

        if ($existing) {
            if (empty($existing->name) && !empty($name)) {
                $existing->update(['name' => $name]);
            }
            return $existing;
        }

        return self::create([
            'shop_id'             => $shopId,
            'name'                => $name,
            'whatsapp'            => $whatsapp,
            'whatsapp_normalized' => $normalized,
        ]);
    }
}
