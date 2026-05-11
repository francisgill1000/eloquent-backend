<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\ShopLoginActivity;
use Illuminate\Http\Request;

class ShopLoginActivityController extends Controller
{
    public function index(Request $request)
    {
        $shop = $this->resolveShop($request);

        if (!$shop) {
            return response()->json(['message' => 'Shop not found'], 404);
        }

        $validated = $request->validate([
            'date_from'     => 'nullable|date',
            'date_to'       => 'nullable|date',
            'login_method'  => 'nullable|in:pin,qr,auto',
            'search'        => 'nullable|string|max:100',
            'per_page'      => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);

        $query = ShopLoginActivity::query()
            ->where('shop_id', $shop->id)
            ->when($validated['date_from'] ?? null, fn($q, $d) => $q->where('logged_in_at', '>=', $d . ' 00:00:00'))
            ->when($validated['date_to'] ?? null, fn($q, $d) => $q->where('logged_in_at', '<=', $d . ' 23:59:59'))
            ->when($validated['login_method'] ?? null, fn($q, $m) => $q->where('login_method', $m))
            ->when($validated['search'] ?? null, function ($q, $s) {
                $q->where(function ($sub) use ($s) {
                    $sub->where('ip_address', 'LIKE', '%' . $s . '%')
                        ->orWhere('device_id', 'LIKE', '%' . $s . '%');
                });
            })
            ->orderByDesc('logged_in_at');

        return response()->json($query->paginate($perPage));
    }

    public function summary(Request $request)
    {
        $shop = $this->resolveShop($request);

        if (!$shop) {
            return response()->json(['message' => 'Shop not found'], 404);
        }

        $latest = ShopLoginActivity::where('shop_id', $shop->id)
            ->orderByDesc('logged_in_at')
            ->first();

        return response()->json([
            'last_login_at'     => $shop->last_login_at,
            'last_login_method' => $latest?->login_method,
            'last_login_ip'     => $latest?->ip_address,
            'last_login_device' => $latest?->device_id,
            'total_logins'      => ShopLoginActivity::where('shop_id', $shop->id)->count(),
        ]);
    }

    private function resolveShop(Request $request): ?Shop
    {
        $user = $request->user();

        if ($user instanceof Shop) {
            return $user;
        }

        $shopId = $request->query('shop_id');

        return $shopId ? Shop::find($shopId) : null;
    }
}
