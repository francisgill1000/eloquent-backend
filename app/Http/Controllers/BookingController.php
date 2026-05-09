<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookSlotRequest;
use App\Models\Booking;
use App\Models\Shop;
use App\Services\Notify;
use App\Services\StaffAssigner;
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

        $bookings = Booking::with(['staff:id,name,is_active', 'shop' => function ($query) use ($deviceId, $isFavouriteOnly, $search) {
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


    /**
     * Look up an existing walk-in customer for this shop by WhatsApp number.
     * Matches on the last 7 digits to tolerate +country code / formatting variants.
     */
    public function lookupCustomer(Request $request, Shop $shop)
    {
        $request->validate([
            'whatsapp' => ['required', 'string', 'min:4', 'max:32'],
        ]);

        $digits = preg_replace('/\D+/', '', $request->whatsapp);
        if (strlen($digits) < 7) {
            return response()->json(['found' => false]);
        }

        $tail = substr($digits, -7);

        // Strip spaces, dashes, plus, parentheses from the stored number so a
        // tail-match works regardless of how the shop typed it originally.
        $normalized = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(customer_whatsapp,' ',''),'-',''),'+',''),'(',''),')','')";

        $query = Booking::where('shop_id', $shop->id)
            ->whereNotNull('customer_whatsapp')
            ->whereRaw("$normalized LIKE ?", ['%' . $tail]);

        $count = (clone $query)->count();
        if ($count === 0) {
            return response()->json(['found' => false]);
        }

        $latest = (clone $query)
            ->orderBy('id', 'desc')
            ->first(['id', 'customer_name', 'customer_whatsapp', 'date']);

        return response()->json([
            'found'           => true,
            'name'            => $latest->customer_name,
            'whatsapp'        => $latest->customer_whatsapp,
            'bookings_count' => $count,
            'last_visit_date' => $latest->date,
        ]);
    }

    /**
     * List walk-in customers for a shop, aggregated from past bookings.
     * One row per unique WhatsApp number (normalized) with totals.
     */
    public function shopCustomers(Request $request, Shop $shop)
    {
        $search  = trim((string) $request->query('search', ''));
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $page    = max(1, (int) $request->query('page', 1));

        $rows = Booking::where('shop_id', $shop->id)
            ->whereNotNull('customer_whatsapp')
            ->where('customer_whatsapp', '!=', '')
            ->orderBy('id', 'desc')
            ->get(['id', 'customer_name', 'customer_whatsapp', 'date', 'charges', 'status']);

        $groups = [];
        foreach ($rows as $b) {
            $normalized = preg_replace('/\D+/', '', $b->customer_whatsapp ?? '');
            if ($normalized === '') continue;
            $key = strlen($normalized) > 7 ? substr($normalized, -9) : $normalized;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'name'             => $b->customer_name,
                    'whatsapp'         => $b->customer_whatsapp,
                    'bookings_count'   => 0,
                    'total_spent'      => 0.0,
                    'last_visit_date'  => $b->date,
                    'first_visit_date' => $b->date,
                ];
            }

            $g = &$groups[$key];
            $g['bookings_count']++;
            if (strtolower((string) $b->status) !== 'cancelled') {
                $g['total_spent'] += (float) ($b->charges ?? 0);
            }
            if ($b->date < $g['first_visit_date']) $g['first_visit_date'] = $b->date;
            if ($b->date > $g['last_visit_date'])  $g['last_visit_date']  = $b->date;
            unset($g);
        }

        $list = array_values($groups);

        if ($search !== '') {
            $needle      = strtolower($search);
            $needleDigits = preg_replace('/\D+/', '', $search);
            $list = array_values(array_filter($list, function ($c) use ($needle, $needleDigits) {
                $nameMatch = $c['name'] && str_contains(strtolower($c['name']), $needle);
                $whatsDigits = preg_replace('/\D+/', '', $c['whatsapp'] ?? '');
                $whatsMatch = $needleDigits !== '' && str_contains($whatsDigits, $needleDigits);
                return $nameMatch || $whatsMatch;
            }));
        }

        usort($list, fn($a, $b) => strcmp($b['last_visit_date'], $a['last_visit_date']));

        $total  = count($list);
        $offset = ($page - 1) * $perPage;
        $items  = array_slice($list, $offset, $perPage);

        return response()->json([
            'data'         => $items,
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'last_page'    => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public function bookSlot(BookSlotRequest $request, Shop $shop)
    {
        try {
            return DB::transaction(function () use ($request, $shop) {

                $date = Carbon::parse($request->date)->format('Y-m-d');
                $startTime = $request->start_time;

                $workingHour = $shop->getWorkingHourOrFail($date);

                $staff = (new StaffAssigner())->pickStaffForSlot(
                    shopId: $shop->id,
                    date: $date,
                    startTime: $startTime
                );

                $booking = Booking::create([
                    'status'            => $staff ? 'booked' : 'queued',
                    'shop_id'           => $shop->id,
                    'staff_id'          => $staff?->id,
                    'date'              => $date,
                    'start_time'        => $startTime,
                    'end_time'          => $shop->getEndSlot(
                        $startTime,
                        $workingHour->slot_duration
                    ),
                    'device_id'         => $request->header('X-Device-Id'),
                    'charges'           => $request->charges ?? 0,
                    'services'          => $request->services ?? [],
                    'customer_name'     => $request->customer_name,
                    'customer_whatsapp' => $request->customer_whatsapp,
                ]);

                $payload = $booking->toArray();
                $payload['notification_url'] = "https://eloquentservice.com/shop/bookings/action?id=" . $payload['id'];

                $message = $staff
                    ? "New booking confirmed: " . $booking->booking_reference . " (assigned to {$staff->name})"
                    : "Booking queued: " . $booking->booking_reference . " (no staff free)";

                Notify::push($shop->id, 'booking', $message, $payload);

                return response()->json([
                    'message' => $staff ? 'Booking confirmed successfully' : 'Booking queued — waiting for a free staff',
                    'data' => $booking,
                ], 201);
            });
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $booking = Booking::with(['shop', 'staff:id,name,is_active', 'invoice'])->find($id);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        return response()->json($booking);
    }

    public function markReminderSent($id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        $booking->update(['reminder_sent_at' => now()]);

        return response()->json(['data' => $booking->fresh()]);
    }

    public function shopBookings(Request $request)
    {
        // 1. Validate the request
        $request->validate([
            'shop_id' => 'required|exists:shops,id',
        ]);

        $shopId = $request->shop_id;
        // Include past 6 days so the dashboard's 7-day trend chart has data.
        $start = now()->subDays(6)->startOfDay();
        $end = now()->addDays(10)->endOfDay();

        // 2. Fetch bookings (Filtering by the actual booking 'date')
        $upcomingBookings = Booking::where('shop_id', $shopId)
            ->whereBetween('date', [$start, $end])
            ->with(['shop:id,name', 'staff:id,name,is_active'])
            ->orderBy('date', 'asc')
            ->get();

        // 3. Aggregate data efficiently
        // Revenue excludes cancelled bookings; cancelled_count is reported separately.
        $stats = Booking::where('shop_id', $shopId)
            ->selectRaw("
                count(*) as total_count,
                sum(case when lower(status) = 'cancelled' then 1 else 0 end) as cancelled_count,
                sum(case when lower(status) = 'cancelled' then 0 else charges end) as total_rev
            ")
            ->first();

        return response()->json([
            'data' => $upcomingBookings,
            'total_bookings' => (int) $stats->total_count,
            'total_revenue' => (float) $stats->total_rev,
            'cancelled_count' => (int) $stats->cancelled_count,
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

        $previousStatus = strtolower($booking->status);
        $previousStaffId = $booking->staff_id;

        $newStatus = strtolower($validated['status']);
        $vacates = in_array($newStatus, ['cancelled', 'completed'], true)
            && in_array($previousStatus, ['booked'], true)
            && $previousStaffId !== null;

        // When a booked slot is being vacated, also free the staff_id so the
        // unique (staff_id, date, start_time) index doesn't block promotion of
        // a queued booking on the same slot.
        $updateData = ['status' => $newStatus];
        if ($vacates) {
            $updateData['staff_id'] = null;
        }
        $booking->update($updateData);

        if ($vacates) {
            (new StaffAssigner())->sweep(
                shopId: $booking->shop_id,
                date: Carbon::parse($booking->date)->format('Y-m-d'),
                startTime: $booking->getRawOriginal('start_time')
            );
        }

        // Invoice lifecycle
        if ($newStatus === 'completed' && $previousStatus === 'booked') {
            \App\Models\BookingInvoice::firstOrCreate(
                ['booking_id' => $booking->id],
                [
                    'subtotal'  => $booking->charges ?? 0,
                    'total'     => $booking->charges ?? 0,
                    'status'    => 'issued',
                    'issued_at' => now(),
                ]
            );
        }

        if ($newStatus === 'cancelled') {
            $booking->load('invoice');
            $booking->invoice?->update(['status' => 'cancelled']);
        }

        return response()->json([
            'message' => 'Booking updated successfully',
            'data' => $booking->fresh(['staff', 'invoice'])
        ]);
    }
}
