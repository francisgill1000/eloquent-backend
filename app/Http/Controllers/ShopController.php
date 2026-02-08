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
            ->whereHas('working_hours')
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

        $created = Shop::create($dataToStore);

        return response()->json($created);
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
        //
    }
}
