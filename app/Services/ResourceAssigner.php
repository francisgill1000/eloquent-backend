<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Catalog;
use App\Models\Resource;
use App\Models\Shop;

/**
 * Assigns a finite resource (room/chair/machine) to a booking, mirroring
 * StaffAssigner. Backward compatible: a booking whose services declare no
 * required resource type needs none, so the whole path is a no-op by default.
 */
class ResourceAssigner
{
    /**
     * The resource type required by a booking's selected services, or null.
     * Uses the first selected service (by catalog id) that declares one.
     */
    public function requiredType(int $shopId, ?array $services): ?string
    {
        if (empty($services)) {
            return null;
        }

        $ids = collect($services)
            ->map(fn ($s) => is_array($s) ? ($s['id'] ?? null) : null)
            ->filter()
            ->all();

        if (empty($ids)) {
            return null;
        }

        return Catalog::where('shop_id', $shopId)
            ->whereIn('id', $ids)
            ->whereNotNull('requires_resource_type')
            ->orderByRaw('(select 0)') // keep selection order stable across drivers
            ->value('requires_resource_type');
    }

    /**
     * Pick a free active resource of the given type for the slot, or null when
     * none is available. "Busy" = held by another non-cancelled booking at the
     * same shop/date/start_time.
     */
    public function pickResourceForSlot(int $shopId, string $date, string $startTime, string $type): ?Resource
    {
        $busyIds = Booking::where('shop_id', $shopId)
            ->where('date', $date)
            ->where('start_time', $startTime)
            ->whereNotNull('resource_id')
            ->whereRaw("lower(status) != 'cancelled'")
            ->pluck('resource_id')
            ->all();

        return Resource::where('shop_id', $shopId)
            ->where('type', $type)
            ->where('is_active', true)
            ->whereNotIn('id', $busyIds)
            ->orderBy('id')
            ->first();
    }
}
