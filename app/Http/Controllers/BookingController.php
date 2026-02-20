<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookSlotRequest;
use App\Models\Booking;
use App\Models\Shop;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class BookingController extends Controller
{

    public function index()
    {
        $deviceId = request()->header('X-Device-Id');
        $isFavouriteOnly = request("is_favourite_only", false);
        $search = request("search");

        $bookings = Booking::with(['shop' => function ($query) use ($deviceId, $isFavouriteOnly, $search) {
            $query->where('status', Shop::ACTIVE)
                ->withCount([
                    'guest_favourites as is_favourite' => function ($q) use ($deviceId) {
                        $q->where('device_id', $deviceId);
                    }
                ])
                ->when($isFavouriteOnly, function ($q) use ($deviceId) {
                    $q->whereHas('guest_favourites', function ($q2) use ($deviceId) {
                        $q2->where('device_id', $deviceId);
                    });
                })
                ->when($search, function ($q) use ($search) {
                    $q->where('shop_code', 'LIKE', $search . '%');
                });
        }])
            ->when($search, function ($q) use ($search) {
                // Search by booking reference (BK00011 format)
                $q->where('booking_reference', 'LIKE', $search . '%');
            })
            ->orderBy("id", "desc")
            ->paginate(request('per_page', 15));

        return response()->json($bookings);
    }


    public function bookSlot(BookSlotRequest $request, Shop $shop)
    {
        try {
            return DB::transaction(function () use ($request, $shop) {

                $date = Carbon::parse($request->date)->format('Y-m-d');
                $startTime = $request->start_time;

                $workingHour = $shop->getWorkingHourOrFail($date);

                Booking::ensureSlotIsAvailableOrFail(
                    $shop->id,
                    $date,
                    $startTime
                );

                $booking = Booking::create([
                    'status'     => 'booked',
                    'shop_id'    => $shop->id,
                    'date'       => $date,
                    'start_time' => $startTime,
                    'end_time'   => $shop->getEndSlot(
                        $startTime,
                        $workingHour->slot_duration
                    ),
                    'device_id'  => $request->header('X-Device-Id'),
                    'charges'    => $request->charges ?? 0,
                    'services'   => $request->services ?? [],
                ]);

                return response()->json([
                    'message' => 'Booking confirmed successfully',
                    'data' => $booking
                ], 201);
            });
        } catch (HttpException $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $booking = Booking::with('shop')->find($id);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        return response()->json($booking);
    }

    /**
     * Get bookings for the authenticated shop
     */
    public function shopBookings(Request $request)
    {
       
        $bookings = Booking::where('shop_id', $request->shop_id ?? 0)
            ->whereBetween('created_at', [
                Carbon::now()->startOfDay(),
                Carbon::now()->addDays(10)->endOfDay()
            ])
            ->with(['shop'])
            ->orderBy('date', 'asc') // Changed to 'asc' so the soonest bookings appear first
            ->get();

        $totalBookings = $bookings->count();
        $totalRevenue = $bookings->sum('charges');

        return response()->json([
            'data' => $bookings,
            'total_bookings' => $totalBookings,
            'total_revenue' => $totalRevenue,
            'dates_range' => [
                'from' => Carbon::now()->startOfDay()->toDateTimeString(),
                'to' => Carbon::now()->addDays(10)->endOfDay()->toDateTimeString(),
            ]
        ]);
    }

    /**
     * Update booking status
     */
    public function update(Request $request, $id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:booked,completed,cancelled,Booked,Completed,Cancelled'
        ]);

        // Store status in lowercase for consistency
        $booking->update(['status' => strtolower($validated['status'])]);

        return response()->json([
            'message' => 'Booking updated successfully',
            'data' => $booking
        ]);
    }
}
