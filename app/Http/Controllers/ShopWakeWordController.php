<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The per-shop voice wake phrase. Saying it on the AI Summary page plays the
 * summary aloud. Null = use the shop's own name, so every tenant gets a working
 * wake word with no setup and no hardcoded identity.
 */
class ShopWakeWordController extends Controller
{
    public function show(Request $request)
    {
        return response()->json($this->payload($this->requireShop($request)));
    }

    public function update(Request $request)
    {
        $shop = $this->requireShop($request);

        // Trim first, then treat an empty result as "clear it" — so a blank
        // field restores the shop-name fallback instead of failing min:3.
        $raw = $request->input('phrase');
        $trimmed = is_string($raw) ? trim($raw) : null;
        $request->merge(['phrase' => ($trimmed === '' ? null : $trimmed)]);

        $data = $request->validate([
            'phrase' => ['nullable', 'string', 'min:3', 'max:60'],
        ]);

        $shop->update(['wake_phrase' => $data['phrase'] ?? null]);

        return response()->json($this->payload($shop->fresh()));
    }

    private function payload(Shop $shop): array
    {
        $custom = is_string($shop->wake_phrase) && trim($shop->wake_phrase) !== '';

        return [
            'phrase' => $custom ? $shop->wake_phrase : null,
            'effective_phrase' => $custom ? $shop->wake_phrase : (string) $shop->name,
            'using_custom' => $custom,
        ];
    }

    private function requireShop(Request $request): Shop
    {
        $user = $request->user();
        if (!$user || !($user instanceof Shop)) {
            throw new HttpException(403, 'Shop authentication required');
        }
        return $user;
    }
}
