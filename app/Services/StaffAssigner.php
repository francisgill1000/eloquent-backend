<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Staff;
use App\Services\Notify;

class StaffAssigner
{
    /**
     * Pick the staff with the fewest bookings today (excluding cancelled),
     * tie-broken by lowest id, who is free for the given slot.
     * Returns null if no active staff is free.
     */
    public function pickStaffForSlot(int $shopId, string $date, string $startTime): ?Staff
    {
        $busyStaffIds = Booking::where('shop_id', $shopId)
            ->where('date', $date)
            ->where('start_time', $startTime)
            ->whereNotNull('staff_id')
            ->pluck('staff_id')
            ->all();

        $candidates = Staff::where('shop_id', $shopId)
            ->where('is_active', true)
            ->whereNotIn('id', $busyStaffIds)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Compute fewest-today count for each candidate
        $counts = Booking::where('shop_id', $shopId)
            ->where('date', $date)
            ->whereIn('staff_id', $candidates->pluck('id'))
            ->where('status', '!=', 'cancelled')
            ->selectRaw('staff_id, COUNT(*) as c')
            ->groupBy('staff_id')
            ->pluck('c', 'staff_id')
            ->all();

        return $candidates
            ->sortBy(fn ($s) => [(int) ($counts[$s->id] ?? 0), $s->id])
            ->first();
    }

    /**
     * After a staff has freed up at a (shop, date, startTime), promote
     * queued bookings to booked when a free staff exists. Returns
     * the bookings that were promoted (in promotion order).
     */
    public function sweep(int $shopId, string $date, string $startTime): array
    {
        $promoted = [];

        while (true) {
            $next = Booking::where('shop_id', $shopId)
                ->where('date', $date)
                ->where('start_time', $startTime)
                ->whereNull('staff_id')
                ->where('status', 'queued')
                ->orderBy('created_at', 'asc')
                ->first();

            if (!$next) break;

            $staff = $this->pickStaffForSlot($shopId, $date, $startTime);
            if (!$staff) break;

            $next->update([
                'staff_id' => $staff->id,
                'status' => 'booked',
            ]);

            Notify::push(
                $shopId,
                'booking',
                "Queued booking promoted: {$next->booking_reference} (assigned to {$staff->name})",
                $next->fresh()->toArray()
            );

            $promoted[] = $next;
        }

        return $promoted;
    }
}
