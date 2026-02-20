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

    public function shopBookings(Request $request)
    {
        // 1. Validate the request
        $request->validate([
            'shop_id' => 'required|exists:shops,id',
        ]);

        $shopId = $request->shop_id;
        $start = now()->startOfDay();
        $end = now()->addDays(10)->endOfDay();

        // 2. Fetch bookings (Filtering by the actual booking 'date')
        $upcomingBookings = Booking::where('shop_id', $shopId)
            ->whereBetween('date', [$start, $end])
            ->with('shop:id,name') // Only pull necessary columns from shop
            ->orderBy('date', 'asc')
            ->get();

        // 3. Aggregate data efficiently 
        // We filter total stats by shop_id (as per your original logic)
        $stats = Booking::where('shop_id', $shopId)
            ->selectRaw('count(*) as total_count, sum(charges) as total_rev')
            ->first();

        return response()->json([
            'data' => $upcomingBookings,
            'total_bookings' => (int) $stats->total_count,
            'total_revenue' => (float) $stats->total_rev,
            'dates_range' => [
                'from' => $start->toDateTimeString(),
                'to' => $end->toDateTimeString(),
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
