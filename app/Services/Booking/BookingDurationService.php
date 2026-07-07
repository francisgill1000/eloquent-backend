<?php

namespace App\Services\Booking;

use App\Models\Catalog;
use App\Models\Shop;

/**
 * Computes a booking's total length (minutes) from its selected services, using
 * each service's per-service duration + buffer when configured. Backward
 * compatible: shops that set no durations keep the single global slot length.
 */
class BookingDurationService
{
    /**
     * @param  array  $services  the booking's services JSON (items may carry a catalog `id`)
     * @param  int    $fallbackSlot  the shop's global slot_duration
     */
    public function computeMinutes(Shop $shop, array $services, int $fallbackSlot): int
    {
        if (empty($services)) {
            return $fallbackSlot;
        }

        // Resolve durations for any catalog ids present, tenant-scoped to this shop.
        $ids = collect($services)
            ->map(fn ($s) => is_array($s) ? ($s['id'] ?? null) : null)
            ->filter()
            ->all();

        $catalogs = $ids
            ? Catalog::where('shop_id', $shop->id)->whereIn('id', $ids)->get()->keyBy('id')
            : collect();

        $anyDuration = $catalogs->contains(fn ($c) => $c->duration_minutes !== null);

        // Legacy behaviour: no per-service durations configured → one global slot.
        if (! $anyDuration) {
            return $fallbackSlot;
        }

        $total = 0;
        foreach ($services as $s) {
            $id = is_array($s) ? ($s['id'] ?? null) : null;
            $catalog = $id ? $catalogs->get($id) : null;
            $duration = $catalog?->duration_minutes ?? $fallbackSlot;
            $buffer = $catalog?->buffer_minutes ?? 0;
            $total += $duration + $buffer;
        }

        return $total > 0 ? $total : $fallbackSlot;
    }
}
