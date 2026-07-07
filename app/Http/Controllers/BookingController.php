<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookSlotRequest;
use App\Models\Booking;
use App\Models\CampaignRecipient;
use App\Models\PromoCode;
use App\Models\Shop;
use App\Models\ShopCustomer;
use App\Services\Notify;
use App\Services\StaffAssigner;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

                $shopCustomer = ShopCustomer::findOrCreateForShop(
                    $shop->id,
                    $request->customer_whatsapp,
                    $request->customer_name
                );

                // Resolve promo code (if provided + redeemable for this shop) and
                // attribute to the most recent campaign that messaged this customer
                // in the last 30 days, if any.
                $subtotal = (float) ($request->charges ?? 0);
                $finalCharges = $subtotal;
                $discountAmount = 0.0;
                $promoCodeId = null;
                $campaignId = null;
                $recipientToAttribute = null;

                $codeStr = strtoupper(preg_replace('/\s+/', '', (string) $request->input('promo_code', '')));
                if ($codeStr !== '') {
                    $promo = PromoCode::where('shop_id', $shop->id)->where('code', $codeStr)->first();
                    if ($promo && $promo->isRedeemable()) {
                        $applied = $promo->applyTo($subtotal);
                        if ($applied) {
                            $discountAmount = $applied['discount'];
                            $finalCharges = $applied['total'];
                            $promoCodeId = $promo->id;
                        }
                    }
                }

                if ($shopCustomer) {
                    $recipientToAttribute = CampaignRecipient::whereHas('campaign', function ($q) use ($shop) {
                            $q->where('shop_id', $shop->id);
                        })
                        ->where('shop_customer_id', $shopCustomer->id)
                        ->whereNull('booking_id')
                        ->where('sent_at', '>=', now()->subDays(30))
                        ->orderByDesc('sent_at')
                        ->first();
                    if ($recipientToAttribute) {
                        $campaignId = $recipientToAttribute->marketing_campaign_id;
                    }
                }

                $booking = app(\App\Services\Booking\BookingCreator::class)->create($shop, [
                    'customer_name'         => $request->customer_name,
                    'customer_whatsapp'     => $request->customer_whatsapp,
                    'date'                  => $date,
                    'start_time'            => $startTime,
                    'services'              => $request->services ?? [],
                    'charges'               => $finalCharges,
                    'discount_amount'       => $discountAmount,
                    'promo_code_id'         => $promoCodeId,
                    'marketing_campaign_id' => $campaignId,
                    'device_id'             => $request->header('X-Device-Id'),
                ]);

                if ($promoCodeId) {
                    PromoCode::where('id', $promoCodeId)->increment('uses_count');
                }
                if ($recipientToAttribute) {
                    $recipientToAttribute->update([
                        'booking_id' => $booking->id,
                        'booked_at'  => now(),
                    ]);
                }

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

    /**
     * Set a booking's per-visit intake notes.
     */
    public function updateNotes(Request $request, $id)
    {
        $booking = Booking::find($id);
        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        $data = $request->validate([
            'notes' => ['present', 'nullable', 'string', 'max:5000'],
        ]);

        $booking->update(['notes' => $data['notes']]);

        return response()->json(['data' => $booking->fresh()]);
    }

    /**
     * Create a recurring series of bookings (weekly regulars). Each occurrence
     * runs through the normal booking pipeline (staff assignment / queue).
     */
    public function bookRecurring(Request $request, Shop $shop)
    {
        if (!$request->header('X-Device-Id')) {
            return response()->json(['message' => 'Device ID missing'], 422);
        }

        $data = $request->validate([
            'date'              => ['required', 'date'],
            'start_time'        => ['required'],
            'services'          => ['required', 'array', 'min:1'],
            'charges'           => ['nullable', 'numeric', 'min:0'],
            'customer_name'     => ['nullable', 'string', 'max:255'],
            'customer_whatsapp' => ['nullable', 'string', 'max:32'],
            'frequency'         => ['required', 'in:weekly,biweekly,monthly'],
            'occurrences'       => ['required', 'integer', 'min:2', 'max:52'],
        ]);

        $result = app(\App\Services\Booking\RecurringBookingService::class)->createSeries(
            $shop,
            [
                'date'              => Carbon::parse($data['date'])->format('Y-m-d'),
                'start_time'        => $data['start_time'],
                'services'          => $data['services'],
                'charges'           => $data['charges'] ?? 0,
                'customer_name'     => $data['customer_name'] ?? null,
                'customer_whatsapp' => $data['customer_whatsapp'] ?? null,
                'device_id'         => $request->header('X-Device-Id'),
            ],
            $data['frequency'],
            (int) $data['occurrences'],
        );

        return response()->json([
            'message'   => 'Recurring series created',
            'series_id' => $result['series_id'],
            'created'   => $result['created'],
            'skipped'   => $result['skipped'],
        ], 201);
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
        // Total bookings and revenue both exclude cancelled bookings; cancelled_count is reported separately.
        $stats = Booking::where('shop_id', $shopId)
            ->selectRaw("
                sum(case when lower(status) = 'cancelled' then 0 else 1 end) as total_count,
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
            'status' => 'required|in:booked,completed,cancelled,queued,no_show,Booked,Completed,Cancelled,Queued,No_show'
        ]);

        $fresh = app(\App\Services\Booking\BookingStatusService::class)->apply($booking, $validated['status']);

        return response()->json([
            'message' => 'Booking updated successfully',
            'data' => $fresh,
        ]);
    }
}
