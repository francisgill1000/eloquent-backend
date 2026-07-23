<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreShopRequest;
use App\Http\Requests\UpdateShopRequest;
use App\Models\Booking;
use App\Models\Shop;
use App\Models\ShopLoginActivity;
use App\Models\ShopUser;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
            ->where('is_master', false)
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
                $query->orWhere('name', 'LIKE', $search . '%');
                $query->orWhere('location', 'LIKE', $search . '%');
            })
            ->paginate(request('per_page', 15));

        return response()->json($shops);
    }

    public function nearby(Request $request)
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'radius_km' => 'nullable|numeric|min:1|max:500',
            'per_page' => 'nullable|integer|min:1|max:100',
            'is_favourite_only' => 'nullable|boolean',
            'search' => 'nullable|string|max:100',
        ]);

        $lat = (float) $validated['lat'];
        $lon = (float) $validated['lon'];
        $radiusKm = (float) ($validated['radius_km'] ?? 2);
        $perPage = (int) ($validated['per_page'] ?? 15);
        $isFavouriteOnly = filter_var($validated['is_favourite_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $search = $validated['search'] ?? null;
        $deviceId = $request->header('X-Device-Id');

        $distanceExpr = "(6371 * ACOS(LEAST(1, GREATEST(-1, COS(RADIANS(?)) * COS(RADIANS(lat)) * COS(RADIANS(lon) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(lat))))))";

        $shops = Shop::query()
            ->where('status', Shop::ACTIVE)
            ->where('is_master', false)
            ->whereNotNull('lat')
            ->whereNotNull('lon')
            ->select('shops.*')
            ->selectRaw($distanceExpr . ' as distance_km', [$lat, $lon, $lat])
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
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('shop_code', 'LIKE', $search . '%')
                        ->orWhere('name', 'LIKE', '%' . $search . '%');
                });
            })
            ->whereRaw($distanceExpr . ' <= ?', [$lat, $lon, $lat, $radiusKm])
            ->orderByRaw($distanceExpr . ' asc', [$lat, $lon, $lat])
            ->paginate($perPage);

        $shops->getCollection()->transform(function ($shop) {
            $distance = (float) ($shop->distance_km ?? 0);
            $shop->distance = number_format($distance, 1) . ' km';
            return $shop;
        });

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

        // Category is chosen at registration and locked from then on.
        $dataToStore['category_confirmed_at'] = now();

        $shop = Shop::create($dataToStore);

        // Every new shop starts a 30-day free trial (Business Lens + Ask).
        app(\App\Services\SubscriptionService::class)->startTrial($shop);

        $token = $shop->createToken('auth_token')->plainTextToken;

        return response()->json([
            'shop' => $shop,
            'token' => $token
        ], 201);
    }

    /**
     * One-time category selection for shops registered before the category
     * dropdown existed. Once confirmed, the category is locked for good.
     */
    public function confirmCategory(Request $request)
    {
        $shop = $request->user();
        if (!$shop || !($shop instanceof Shop)) {
            return response()->json(['message' => 'Shop authentication required'], 403);
        }

        // The category is chosen once (at registration) and then fixed. If it is
        // already set, treat this as a no-op success and return the shop as-is —
        // a new account that already has a category should never be blocked here
        // (the previous 422 surfaced as a confusing "category is locked" error).
        if ($shop->category_confirmed_at) {
            return response()->json([
                'message' => 'Category already set',
                'shop' => $shop->fresh(),
            ]);
        }

        $data = $request->validate([
            'category_id' => 'required|integer|in:' . implode(',', \App\Support\ServiceCategories::ids()),
        ]);

        $shop->update([
            'category_id' => $data['category_id'],
            'category_confirmed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Category saved',
            'shop' => $shop->fresh(),
        ]);
    }

    public function login(Request $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if (!$email || !$password) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $shop = Shop::where('email', $email)->first();

        if ($shop) {
            if (!$shop->password || !Hash::check((string) $password, $shop->password)) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }

            setPermissionsTeamId($shop->id);

            $token = $shop->createToken('auth_token')->plainTextToken;
            $permissions = [\App\Support\Rbac::WILDCARD];
            $user = ['id' => null, 'name' => $shop->name, 'is_active' => true];

            $shop->recordLogin($request, ShopLoginActivity::METHOD_PASSWORD);

            return response()->json([
                'shop' => $shop,
                'user' => $user,
                'permissions' => $permissions,
                'token' => $token
            ], 201);
        }

        // Not a Shop (owner) email — try a staff (ShopUser) account.
        $shopUser = ShopUser::where('email', $email)->where('is_active', true)->first();

        if (!$shopUser || !$shopUser->password || !Hash::check((string) $password, $shopUser->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $staffShop = $shopUser->shop;
        setPermissionsTeamId($staffShop->id);

        $newToken = $staffShop->createToken('auth_token');
        $newToken->accessToken->forceFill(['shop_user_id' => $shopUser->id])->save();

        $permissions = \App\Support\Rbac::permissionsFor($shopUser->load('roles'));
        $user = ['id' => $shopUser->id, 'name' => $shopUser->name, 'is_active' => (bool) $shopUser->is_active];

        $staffShop->recordLogin($request, ShopLoginActivity::METHOD_PASSWORD);

        return response()->json([
            'shop' => $staffShop,
            'user' => $user,
            'permissions' => $permissions,
            'token' => $newToken->plainTextToken
        ], 201);
    }

    public function show(Request $request, Shop $shop)
    {
        $shop->load(['working_hours', 'catalogs.parentCategory']);
        $date = $request->query('date', now()->toDateString());

        // Resolve working hours for the requested date (not just today)
        $dayOfWeek = Carbon::parse($date)->dayOfWeek;
        $workingHour = $shop->working_hours->firstWhere('day_of_week', $dayOfWeek);

        if ($workingHour) {
            $shop->slots = $shop::getSlots(
                $date,
                $workingHour->start_time ?? "00:00:00",
                $workingHour->end_time ?? "00:00:00",
                $workingHour->slot_duration ?? 30,
                $shop->id
            );
        } else {
            // Shop closed on requested day
            $shop->slots = [];
        }
        $shop->date = $date;
        $shop->rating = 5;
        return response()->json($shop);
    }

    public function update(UpdateShopRequest $request, Shop $shop)
    {
        // Editing working hours is its own grantable permission (owner and untagged
        // sessions bypass, see Rbac). Checked before the try so the 403 isn't
        // swallowed into a 500. Profile fields below are not gated (unchanged).
        if (is_array($request->validated()['working_hours'] ?? null)) {
            abort_unless(
                \App\Support\Rbac::userCan(current_shop_user(), 'working_hours.manage'),
                403,
                'This action is not permitted.'
            );
        }

        try {
            $validated = $request->validated();

            // Extract image fields for special handling
            $logo = $validated['logo'] ?? null;
            $heroImage = $validated['hero_image'] ?? null;
            $workingHours = $validated['working_hours'] ?? null;

            // Remove image fields from validated data
            unset($validated['logo'], $validated['hero_image'], $validated['working_hours']);

            // Mass assign validated fields
            $shop->fill($validated);

            // Handle logo upload (base64)
            if (!empty($logo)) {
                $shop->logo = Shop::saveBase64Image($logo, "logos");
            }

            // Handle hero image upload (base64)
            if (!empty($heroImage)) {
                $shop->hero_image = Shop::saveBase64Image($heroImage, "hero_images");
            }

            // Save the shop
            $shop->save();

            if (is_array($workingHours)) {
                $this->syncWorkingHours($shop, $workingHours);
            }

            $shop->load('working_hours');

            return response()->json([
                'message' => 'Shop updated successfully',
                'shop' => $shop
            ], 200);
        } catch (Throwable $e) {
            Log::error('Shop update error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update shop',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function syncWorkingHours(Shop $shop, array $workingHours): void
    {
        $dayIndexes = collect($workingHours)
            ->pluck('day_of_week')
            ->map(fn($day) => (int) $day)
            ->values()
            ->all();

        if (empty($dayIndexes)) {
            $shop->working_hours()->delete();
            return;
        }

        $shop->working_hours()
            ->whereNotIn('day_of_week', $dayIndexes)
            ->delete();

        foreach ($workingHours as $entry) {
            $shop->working_hours()->updateOrCreate(
                ['day_of_week' => (int) $entry['day_of_week']],
                [
                    'start_time' => $entry['start_time'],
                    'end_time' => $entry['end_time'],
                    'slot_duration' => (int) ($entry['slot_duration'] ?? 30),
                ]
            );
        }
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

            $shop->recordLogin($request, ShopLoginActivity::METHOD_AUTO);

            return response()->json([
                'authenticated' => true,
                'token' => $token,
                'shop' => $shop,
            ]);
        }

        return response()->json(['authenticated' => false], 404);
    }


    public function bookings(Request $request)
    {
        $search = request("search");
        $status = request("status");
        // Tenant is the authenticated shop — never a request-supplied shop_id.
        $shop_id = (int) $request->user()->id;

        $bookings = Booking::where('shop_id', $shop_id)
            ->with('staff:id,name,is_active')
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
