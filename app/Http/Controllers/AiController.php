<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\Ai\AssistantAgent;
use App\Services\Ai\AssistantTools;
use App\Services\Wa\ClaudeClient;
use App\Support\ServiceCategories;
use Illuminate\Http\Request;

/**
 * App-aware assistant for the customer app. Runs a Claude tool loop: read tools
 * (favourites, bookings, account, shops, categories) execute server-side and are
 * device-scoped; action tools (navigate, register, login) return a directive the
 * client executes. Matching shops come back in the SAME shape ShopCard consumes.
 */
class AiController extends Controller
{
    public function search(Request $request)
    {
        $validated = $request->validate([
            'messages' => 'array',
            'messages.*.role' => 'required_with:messages|string|in:user,assistant',
            'messages.*.content' => 'required_with:messages|string|max:2000',
            'message' => 'nullable|string|max:2000',
            'lat' => 'nullable|numeric|between:-90,90',
            'lon' => 'nullable|numeric|between:-180,180',
        ]);

        $messages = $validated['messages'] ?? [];
        if (!$messages && !empty($validated['message'])) {
            $messages = [['role' => 'user', 'content' => $validated['message']]];
        }
        if (!$messages) {
            return response()->json(['reply' => 'How can I help?', 'action' => null, 'shops' => [], 'categories' => []]);
        }

        $tools = new AssistantTools(
            (string) $request->header('X-Device-Id'),
            $request->user(),
            isset($validated['lat']) ? (float) $validated['lat'] : null,
            isset($validated['lon']) ? (float) $validated['lon'] : null,
        );

        try {
            $out = (new AssistantAgent(new ClaudeClient()))->run($this->systemPrompt(), $messages, $tools);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'reply' => 'Something went wrong on my side — please try again.',
                'action' => null, 'shops' => [], 'categories' => [],
            ]);
        }

        return response()->json([
            'reply' => $out['reply'] !== '' ? $out['reply'] : 'Sorry, I did not catch that — could you rephrase?',
            'action' => $out['action'],
            'shops' => $out['shops']->values(),
            'categories' => [],
        ]);
    }

    /**
     * Service categories that currently have at least one bookable shop, with a
     * shop count each — used for the "what can I search?" chips. No Claude call.
     */
    public function categories()
    {
        $counts = Shop::where('status', Shop::ACTIVE)
            ->where('is_master', false)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) as cnt')
            ->groupBy('category_id')
            ->pluck('cnt', 'category_id');

        $categories = collect(ServiceCategories::all())
            ->filter(fn ($c) => (int) ($counts[$c['id']] ?? 0) > 0)
            ->map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'count' => (int) ($counts[$c['id']] ?? 0)])
            ->values();

        return response()->json(['categories' => $categories]);
    }

    private function systemPrompt(): string
    {
        $catalogue = collect(ServiceCategories::all())
            ->map(fn ($c) => "{$c['id']} = {$c['name']}")
            ->implode("\n");

        return <<<SYS
You are the in-app assistant for Eloquent Bookings. The app is called "Eloquent Bookings" — always use that name, never any other brand name. Eloquent Bookings lists local service shops customers can browse, favourite and book.

You can:
- Answer about the user's own favourites, bookings, and account.
- Search and describe shops and the services they offer.
- Take the user to any app screen, and sign them in or create their account.

The service categories are:
{$catalogue}

Use the tools rather than guessing. Prefer the most specific tool. To find shops use search_shops (pass near=true only when the user implies location). For "my favourites"/"my bookings"/"my account" use list_favourites/list_bookings/get_account.

To take the user somewhere, call navigate with one of the allowed routes. To create an account call register (collect the name and phone in conversation first); to sign in call login (collect the phone first). NEVER ask for or repeat a password — the app collects it securely after you call register/login.

If get_account returns logged_in:false and the user wants account-only info, offer to sign them in (call login).

Keep every reply to one or two short, friendly sentences. If a request is unrelated to Eloquent Bookings, say you can only help with local services and bookings.
SYS;
    }
}
