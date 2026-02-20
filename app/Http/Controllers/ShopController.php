<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreShopRequest;
use App\Models\Booking;
use App\Models\Shop;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Throwable;

class ShopController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $deviceId = request()->header('X-Device-Id');

        $isFavouriteOnly = request("is_favourite_only", false);
        $search = request("search");

        $shops = Shop::where('status', Shop::ACTIVE)
            // ->whereHas('working_hours')
            ->withCount([
                'guest_favourites as is_favourite' => function ($q) use ($deviceId) {
                    $q->where('device_id', $deviceId);
                }
            ])
            ->when($isFavouriteOnly, function ($query) use ($deviceId) {
                $query->whereHas('guest_favourites', function ($q) use ($deviceId) {
                    $q->where('device_id', $deviceId);
                });
            })
            ->with('today_working_hours')
            ->when($search, function ($query) use ($search) {
                $query->where('shop_code', 'LIKE', $search . '%');
            })
            ->paginate(request('per_page', 15));

        return response()->json($shops);
    }

    public function store(StoreShopRequest $request)
    {
        $dataToStore = $request->validated();

        if (!empty($dataToStore['logo'])) {
            $dataToStore['logo'] = Shop::saveBase64Image($dataToStore['logo'], "logos");
        }

        if (!empty($dataToStore['hero_image'])) {
            $dataToStore['hero_image'] = Shop::saveBase64Image($dataToStore['hero_image'], "hero_images");
        }

        $shop = Shop::create($dataToStore);

        $token = $shop->createToken('auth_token')->plainTextToken;

        return response()->json([
            'shop' => $shop,
            'token' => $token
        ], 201);
    }

    public function login(Request $request)
    {
        $shopCode = $request->input('shop_code');
        $pin = $request->input('pin');

        $shop = Shop::where('shop_code', $shopCode)->first();

        if (!$shop) {
            return response()->json(['message' => 'Shop not found'], 404);
        }

        if ($shop->pin !== $pin) {
            return response()->json(['message' => 'Invalid PIN'], 401);
        }

        $token = $shop->createToken('auth_token')->plainTextToken;

        return response()->json([
            'shop' => $shop,
            'token' => $token
        ], 201);
    }

    public function show(Request $request, Shop $shop)
    {
        $shop->load(['working_hours', 'catalogs']);

        $date = $request->query('date', now()->toDateString());
        $workingHour = $shop->today_working_hours;
        $shop->slots = $shop::getSlots($date, $workingHour->start_time ?? "00:00:00", $workingHour->end_time ?? "00:00:00", $workingHour->slot_duration ?? 30, $shop->id);
        $shop->date = $date;
        $shop->rating = 5;
        return response()->json($shop);
    }

    public function update(Request $request, Shop $shop)
    {
        // Verify the shop owns this request (protect with auth)
        // Update working hours if provided
        if ($request->has('working_hours')) {
            $workingHoursData = $request->input('working_hours');

            // Validate working hours data
            $validated = $request->validate([
                'working_hours' => 'required|array',
                'working_hours.*.day_of_week' => 'required|integer|between:0,6',
                'working_hours.*.start_time' => 'required|date_format:H:i',
                'working_hours.*.end_time' => 'required|date_format:H:i',
                'working_hours.*.slot_duration' => 'integer|min:15|max:120'
            ]);

            // Delete existing working hours for this shop
            $shop->working_hours()->delete();

            // Create new working hours entries
            foreach ($validated['working_hours'] as $hours) {
                $shop->working_hours()->create([
                    'day_of_week' => $hours['day_of_week'],
                    'start_time' => $hours['start_time'],
                    'end_time' => $hours['end_time'],
                    'slot_duration' => $hours['slot_duration'] ?? 30
                ]);
            }
        }

        return response()->json([
            'message' => 'Working hours updated successfully',
            'working_hours' => $shop->working_hours()->get()
        ]);
    }


    public function login_log(Request $request)
    {
        $deviceId = $request->header('X-Device-Id');

        if (!$deviceId) {
            return response()->json(['message' => 'Device ID missing'], 400);
        }

        // Find the shop linked to this specific device
        $shop = Shop::where('device_id', $deviceId)->first();

        if ($shop) {
            // Generate a new token for this session
            $token = $shop->createToken('auto_login_token')->plainTextToken;

            return response()->json([
                'authenticated' => true,
                'token' => $token,
                'shop' => $shop
            ]);
        }

        return response()->json(['authenticated' => false], 404);
    }


    public function bookings()
    {
        $search = request("search");
        $status = request("status");
        $shop_id = request("shop_id");

        $bookings = Booking::where('shop_id', $shop_id)
            ->when($search, function ($q) use ($search) {
                // Search by booking reference (BK00011 format)
                $q->where('booking_reference', 'LIKE', $search . '%');
            })
            ->when($status, function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->orderBy("id", "desc")
            ->paginate(request('per_page', 15));

        return response()->json($bookings);
    }
}
