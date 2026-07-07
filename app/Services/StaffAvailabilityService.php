<?php

namespace App\Services;

use App\Models\Staff;
use Carbon\Carbon;

/**
 * Single source of truth for whether a staff member can take a slot, layering
 * (1) time-off windows (full-day or partial) and (2) optional per-staff weekly
 * shifts on top of the shop's hours. A staff with no schedule rows inherits shop
 * hours (backward compatible); one with schedules is constrained to their shifts.
 */
class StaffAvailabilityService
{
    public function isAvailable(Staff $staff, string $date, string $startTime): bool
    {
        $slot = $this->toTime($startTime);
        $dow = (int) Carbon::parse($date)->dayOfWeek; // 0=Sun..6=Sat

        // 1) Time-off always wins.
        $offRows = $staff->timeOff()->whereDate('date', $date)->get();
        foreach ($offRows as $off) {
            // Full-day off (no times) blocks everything.
            if ($off->start_time === null || $off->end_time === null) {
                return false;
            }
            $from = $this->toTime($off->start_time);
            $to = $this->toTime($off->end_time);
            if ($slot >= $from && $slot < $to) {
                return false;
            }
        }

        // 2) Schedule constraint (only if the staff defines any schedule).
        $schedules = $staff->schedules()->get();
        if ($schedules->isEmpty()) {
            return true; // inherit shop hours
        }

        $shifts = $schedules->where('day_of_week', $dow);
        if ($shifts->isEmpty()) {
            return false; // no shift this weekday = day off
        }

        foreach ($shifts as $shift) {
            $from = $this->toTime($shift->start_time);
            $to = $this->toTime($shift->end_time);
            if ($slot >= $from && $slot < $to) {
                return true;
            }
        }

        return false;
    }

    private function toTime(string $value): string
    {
        return Carbon::parse($value)->format('H:i:s');
    }
}
