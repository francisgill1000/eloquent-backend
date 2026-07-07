<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\Staff;
use App\Models\StaffTimeOff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Owner-managed per-staff weekly schedules + time-off. All actions verify the
 * staff belongs to the shop (tenant-scoped). No payment code.
 */
class StaffAvailabilityController extends Controller
{
    private function ensure(Shop $shop, Staff $staff): void
    {
        abort_unless((int) $staff->shop_id === (int) $shop->id, 404);
    }

    public function schedule(Shop $shop, Staff $staff)
    {
        $this->ensure($shop, $staff);
        return response()->json(['data' => $staff->schedules()->orderBy('day_of_week')->get()]);
    }

    /** Replace the staff's whole weekly schedule atomically. */
    public function setSchedule(Request $request, Shop $shop, Staff $staff)
    {
        $this->ensure($shop, $staff);

        $data = $request->validate([
            'schedule'                 => ['present', 'array'],
            'schedule.*.day_of_week'   => ['required', 'integer', 'between:0,6'],
            'schedule.*.start_time'    => ['required', 'date_format:H:i,H:i:s'],
            'schedule.*.end_time'      => ['required', 'date_format:H:i,H:i:s', 'after:schedule.*.start_time'],
        ]);

        DB::transaction(function () use ($shop, $staff, $data) {
            $staff->schedules()->delete();
            foreach ($data['schedule'] as $row) {
                $staff->schedules()->create([
                    'shop_id'     => $shop->id,
                    'day_of_week' => $row['day_of_week'],
                    'start_time'  => $row['start_time'],
                    'end_time'    => $row['end_time'],
                ]);
            }
        });

        return response()->json(['data' => $staff->schedules()->orderBy('day_of_week')->get()]);
    }

    public function timeOffIndex(Shop $shop, Staff $staff)
    {
        $this->ensure($shop, $staff);
        return response()->json(['data' => $staff->timeOff()->orderBy('date')->get()]);
    }

    public function addTimeOff(Request $request, Shop $shop, Staff $staff)
    {
        $this->ensure($shop, $staff);

        $data = $request->validate([
            'date'       => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i,H:i:s'],
            'end_time'   => ['nullable', 'date_format:H:i,H:i:s', 'after:start_time'],
            'reason'     => ['nullable', 'string', 'max:255'],
        ]);

        $off = $staff->timeOff()->create([
            'shop_id'    => $shop->id,
            'date'       => $data['date'],
            'start_time' => $data['start_time'] ?? null,
            'end_time'   => $data['end_time'] ?? null,
            'reason'     => $data['reason'] ?? null,
        ]);

        return response()->json(['data' => $off], 201);
    }

    public function deleteTimeOff(Shop $shop, Staff $staff, StaffTimeOff $timeOff)
    {
        $this->ensure($shop, $staff);
        abort_unless((int) $timeOff->staff_id === (int) $staff->id, 404);
        $timeOff->delete();
        return response()->json(['message' => 'Time off removed']);
    }
}
