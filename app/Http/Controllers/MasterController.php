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
        $master = $this->requireMaster($request);

        $waShopIds = WaAccount::pluck('phone_number', 'shop_id');

        $shops = Shop::query()
            ->where('id', '!=', $master->id) // the master's own account isn't a business
            ->withCount('bookings')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Shop $shop) => $this->presentShop($shop, $waShopIds));

        return response()->json(['data' => $shops]);
    }

    /**
     * Master-only: update a business's visibility (status) and/or its WhatsApp
     * assistant persona. Blank persona clears back to the default category prompt.
     */
    public function updateShop(Request $request, Shop $shop)
    {
        $this->requireMaster($request);

        $data = $request->validate([
            'status' => ['sometimes', 'in:active,inactive'],
            'persona' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        if (array_key_exists('persona', $data)) {
            $data['persona'] = trim((string) $data['persona']) !== '' ? $data['persona'] : null;
        }

        $shop->update($data);

        $shop->loadCount('bookings');
        $waShopIds = WaAccount::pluck('phone_number', 'shop_id');

        return response()->json(['data' => $this->presentShop($shop, $waShopIds)]);
    }

    /** Shape one business for the master views (list + update). */
    private function presentShop(Shop $shop, $waShopIds): array
    {
        return [
            'id' => $shop->id,
            'name' => $shop->name,
            'shop_code' => $shop->shop_code,
            'pin' => $shop->pin,
            'phone' => $shop->phone,
            'location' => $shop->location,
            'category' => ServiceCategories::name((int) $shop->category_id),
            'status' => $shop->status,
            'persona' => $shop->persona,
            'is_master' => (bool) $shop->is_master,
            'bookings_count' => $shop->bookings_count,
            'wa_connected' => $waShopIds->has($shop->id),
            'wa_number' => $waShopIds->get($shop->id),
            'last_login_at' => optional($shop->last_login_at)->toIso8601String(),
            'created_at' => optional($shop->created_at)->toIso8601String(),
        ];
    }

}
