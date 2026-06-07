<?php

namespace App\Http\Controllers;

use App\Models\BotPrompt;
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

    /**
     * Bot prompt presets for the sales/test number. The default ("Sales Bot")
     * is the normal behaviour; activating a custom one makes the bot reply with
     * that persona for everyone on the sales number — a live test switch.
     */
    public function botPrompts(Request $request)
    {
        $this->requireMaster($request);

        $prompts = BotPrompt::orderByDesc('is_default')->orderBy('id')->get();

        return response()->json(['data' => $prompts]);
    }

    public function storeBotPrompt(Request $request)
    {
        $this->requireMaster($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $prompt = BotPrompt::create([
            'name' => $data['name'],
            'body' => $data['body'],
            'is_default' => false,
            'is_active' => false,
        ]);

        return response()->json(['data' => $prompt], 201);
    }

    public function updateBotPrompt(Request $request, BotPrompt $botPrompt)
    {
        $this->requireMaster($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'body' => ['sometimes', 'string', 'max:20000'],
        ]);

        // is_default / is_active are never edited directly here — activation has
        // its own endpoint and the default flag is fixed at seed time.
        $botPrompt->update($data);

        return response()->json(['data' => $botPrompt]);
    }

    public function activateBotPrompt(Request $request, BotPrompt $botPrompt)
    {
        $this->requireMaster($request);

        BotPrompt::where('is_active', true)->update(['is_active' => false]);
        $botPrompt->update(['is_active' => true]);

        return response()->json(['data' => $botPrompt->fresh()]);
    }

    public function deleteBotPrompt(Request $request, BotPrompt $botPrompt)
    {
        $this->requireMaster($request);

        if ($botPrompt->is_default) {
            return response()->json(['message' => 'The default prompt cannot be deleted.'], 422);
        }

        $wasActive = $botPrompt->is_active;
        $botPrompt->delete();

        // Never leave the bot without an active prompt — fall back to default.
        if ($wasActive) {
            BotPrompt::where('is_default', true)->update(['is_active' => true]);
        }

        return response()->json(['ok' => true]);
    }
}
