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
            ->with('subscription')
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
            'subscription_status' => optional($shop->subscription)->status,
            'plan' => optional($shop->subscription)->plan,
            'access_until' => optional(optional($shop->subscription)->access_until)->toIso8601String(),
            'days_left' => optional($shop->subscription)->daysLeft() ?? 0,
            'bookings_count' => $shop->bookings_count,
            'wa_connected' => $waShopIds->has($shop->id),
            'wa_number' => $waShopIds->get($shop->id),
            'last_login_at' => optional($shop->last_login_at)->toIso8601String(),
            'created_at' => optional($shop->created_at)->toIso8601String(),
        ];
    }

    /** Master-only: current subscription prices (fils). */
    public function pricing(Request $request)
    {
        $this->requireMaster($request);

        return response()->json([
            'monthly' => \App\Models\Pricing::fils('monthly'),
            'annual' => \App\Models\Pricing::fils('annual'),
        ]);
    }

    /** Master-only: update subscription prices. Applies to new payments only. */
    public function updatePricing(Request $request)
    {
        $this->requireMaster($request);

        $data = $request->validate([
            'monthly_fils' => ['required', 'integer', 'min:200'],
            'annual_fils' => ['required', 'integer', 'min:200'],
        ]);

        \App\Models\Pricing::where('plan', 'monthly')->update(['price_fils' => $data['monthly_fils']]);
        \App\Models\Pricing::where('plan', 'annual')->update(['price_fils' => $data['annual_fils']]);

        return response()->json(['monthly' => $data['monthly_fils'], 'annual' => $data['annual_fils']]);
    }

    /** Master-only: manually grant/extend a shop's access (comp or fix). */
    public function grantSubscription(Request $request, Shop $shop)
    {
        $this->requireMaster($request);

        $data = $request->validate(['grant_days' => ['required', 'integer', 'min:1', 'max:3650']]);

        $sub = $shop->subscription()->firstOrCreate([], ['status' => 'expired', 'access_until' => now()]);
        $base = ($sub->access_until && $sub->access_until->isFuture()) ? $sub->access_until : now();
        $sub->update([
            'access_until' => $base->copy()->addDays($data['grant_days']),
            'status' => 'active',
        ]);

        return response()->json([
            'ok' => true,
            'access_until' => $sub->access_until,
            'days_left' => $sub->daysLeft(),
        ]);
    }
}
