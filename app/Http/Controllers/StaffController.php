<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Models\Shop;
use App\Models\Staff;
use App\Services\StaffAssigner;

class StaffController extends Controller
{
    public function index(Shop $shop)
    {
        return response()->json([
            'data' => $shop->staff()->orderBy('id')->get(),
        ]);
    }

    public function store(StoreStaffRequest $request, Shop $shop)
    {
        $staff = $shop->staff()->create([
            'name' => $request->name,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['data' => $staff], 201);
    }

    public function show(Shop $shop, Staff $staff)
    {
        abort_unless($staff->shop_id === $shop->id, 404);
        return response()->json(['data' => $staff]);
    }

    public function update(UpdateStaffRequest $request, Shop $shop, Staff $staff)
    {
        abort_unless($staff->shop_id === $shop->id, 404);

        $wasInactive = !$staff->is_active;
        $staff->update($request->only(['name', 'is_active']));

        // If staff was just (re)activated, sweep all queued bookings for the shop.
        if ($wasInactive && $staff->fresh()->is_active) {
            $this->sweepAllQueuedForShop($shop->id);
        }

        return response()->json(['data' => $staff->fresh()]);
    }

    public function destroy(Shop $shop, Staff $staff)
    {
        abort_unless($staff->shop_id === $shop->id, 404);
        $staff->update(['is_active' => false]);
        return response()->json(['data' => $staff->fresh()]);
    }

    private function sweepAllQueuedForShop(int $shopId): void
    {
        $assigner = new StaffAssigner();
        $slots = \App\Models\Booking::where('shop_id', $shopId)
            ->whereNull('staff_id')
            ->where('status', 'queued')
            ->select('date', 'start_time')
            ->distinct()
            ->get();

        foreach ($slots as $row) {
            $assigner->sweep(
                shopId: $shopId,
                date: \Carbon\Carbon::parse($row->date)->format('Y-m-d'),
                startTime: $row->getRawOriginal('start_time'),
            );
        }
    }
}
