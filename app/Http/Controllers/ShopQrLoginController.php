<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ShopQrLoginController extends Controller
{
    private const CACHE_PREFIX = 'shop_qr_login:';
    private const REQUEST_TTL_SECONDS = 120;
    private const APPROVED_TTL_SECONDS = 120;

    public function requestLogin()
    {
        $token = (string) Str::uuid();

        Cache::put(
            self::CACHE_PREFIX . $token,
            [
                'status' => 'pending',
                'created_at' => now()->toISOString(),
            ],
            now()->addSeconds(self::REQUEST_TTL_SECONDS)
        );

        return response()->json([
            'token' => $token,
            'status' => 'pending',
            'expires_in' => self::REQUEST_TTL_SECONDS,
        ]);
    }

    public function status(string $token)
    {
        $payload = Cache::get(self::CACHE_PREFIX . $token);

        if (!$payload) {
            return response()->json([
                'status' => 'expired',
                'message' => 'QR token expired. Please refresh and scan again.',
            ], 410);
        }

        if (($payload['status'] ?? null) !== 'approved') {
            return response()->json([
                'status' => 'pending',
            ]);
        }

        $shopId = $payload['shop_id'] ?? null;
        $authToken = $payload['auth_token'] ?? null;

        if (!$shopId || !$authToken) {
            Cache::forget(self::CACHE_PREFIX . $token);

            return response()->json([
                'status' => 'expired',
                'message' => 'QR token is no longer valid.',
            ], 410);
        }

        $shop = Shop::find($shopId);
        Cache::forget(self::CACHE_PREFIX . $token);

        if (!$shop) {
            return response()->json([
                'status' => 'expired',
                'message' => 'Shop not found for this login session.',
            ], 404);
        }

        return response()->json([
            'status' => 'approved',
            'shop' => $shop,
            'token' => $authToken,
        ]);
    }

    public function approve(Request $request, string $token)
    {
        $cacheKey = self::CACHE_PREFIX . $token;
        $payload = Cache::get($cacheKey);

        if (!$payload) {
            return response()->json([
                'message' => 'QR token expired. Please scan a fresh code.',
            ], 410);
        }

        if (($payload['status'] ?? null) === 'approved') {
            return response()->json([
                'message' => 'This QR has already been approved.',
            ], 409);
        }

        $authUser = $request->user();

        if (!$authUser || !isset($authUser->id)) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $shop = Shop::find($authUser->id);

        if (!$shop) {
            return response()->json([
                'message' => 'Only shop accounts can approve QR login.',
            ], 403);
        }

        $tokenValue = $shop->createToken('qr_login_token')->plainTextToken;

        Cache::put(
            $cacheKey,
            [
                'status' => 'approved',
                'shop_id' => $shop->id,
                'auth_token' => $tokenValue,
                'approved_at' => now()->toISOString(),
            ],
            now()->addSeconds(self::APPROVED_TTL_SECONDS)
        );

        return response()->json([
            'message' => 'Login approved successfully.',
        ]);
    }
}
