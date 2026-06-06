<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\WaAccount;
use App\Support\ServiceCategories;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MasterController extends Controller
{
    private function requireMaster(Request $request): Shop
    {
        $user = $request->user();

        if (!$user || !($user instanceof Shop) || !$user->is_master) {
            throw new HttpException(403, 'Master access required');
        }

        return $user;
    }

    /**
     * Owner-only overview of every business: credentials, contact info,
     * category, activity, and WhatsApp connection state.
     */
    public function shops(Request $request)
    {
        $this->requireMaster($request);

        $waShopIds = WaAccount::pluck('phone_number', 'shop_id');

        $shops = Shop::query()
            ->withCount('bookings')
            ->orderByDesc('id')
            ->get()
            ->map(function (Shop $shop) use ($waShopIds) {
                return [
                    'id' => $shop->id,
                    'name' => $shop->name,
                    'shop_code' => $shop->shop_code,
                    'pin' => $shop->pin,
                    'phone' => $shop->phone,
                    'location' => $shop->location,
                    'category' => ServiceCategories::name((int) $shop->category_id),
                    'status' => $shop->status,
                    'is_master' => (bool) $shop->is_master,
                    'bookings_count' => $shop->bookings_count,
                    'wa_connected' => $waShopIds->has($shop->id),
                    'wa_number' => $waShopIds->get($shop->id),
                    'last_login_at' => optional($shop->last_login_at)->toIso8601String(),
                    'created_at' => optional($shop->created_at)->toIso8601String(),
                ];
            });

        return response()->json(['data' => $shops]);
    }
}
