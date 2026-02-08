<?php

namespace App\Http\Controllers;

use App\Models\GuestFavourite;
use App\Models\Shop;
use Illuminate\Http\Request;

class GuestFavouriteController extends Controller
{
    public function index(Request $request)
    {
        $deviceId = $request->header('X-Device-Id');

        $shops = Shop::withCount([
            'guestFavourites as is_favourite' => function ($q) use ($deviceId) {
                $q->where('device_id', $deviceId);
            }
        ])->get();

        return response()->json($shops);
    }

    public function toggle(Request $request, Shop $shop)
    {
        $deviceId = $request->header('X-Device-Id');

        if (!$deviceId) {
            return response()->json([
                'message' => 'Device ID missing'
            ], 422);
        }

        $favourite = GuestFavourite::where('device_id', $deviceId)
            ->where('shop_id', $shop->id)
            ->first();

        // If already favourite → remove
        if ($favourite) {
            $favourite->delete();

            return response()->json([
                'status' => 'removed'
            ]);
        }

        // Else → add
        GuestFavourite::create([
            'device_id' => $deviceId,
            'shop_id' => $shop->id
        ]);

        return response()->json([
            'status' => 'added'
        ]);
    }
}
