<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\Wa\ClaudeClient;
use App\Support\ServiceCategories;
use Illuminate\Http\Request;

/**
 * Natural-language service finder for the customer app. One cheap Claude call
 * classifies the customer's message into one of the fixed service categories,
 * a "what services are available" list request, or off-topic. Matching shops
 * come back in the SAME shape the customer ShopCard already consumes (see
 * ShopController::index/nearby). Text-only; no booking tools, no persistence.
 */
class AiController extends Controller
{
    public function search(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:300',
            'lat' => 'nullable|numeric|between:-90,90',
            'lon' => 'nullable|numeric|between:-180,180',
        ]);

        $intent = $this->classify($validated['message']);

        // "What services are available?" — reply with the tappable category list.
        if ($intent['intent'] === 'list') {
            $categories = $this->availableCategories();
            return response()->json([
                'reply' => $intent['reply'] ?: 'Here are the services you can search 👇',
                'category_id' => null,
                'shops' => [],
                'categories' => $categories,
            ]);
        }

        // Off-topic, or no usable category — reply only, no shops.
        if ($intent['intent'] !== 'find' || !$intent['category_id']) {
            return response()->json([
                'reply' => $intent['reply'],
                'category_id' => null,
                'shops' => [],
                'categories' => [],
            ]);
        }

        $shops = $this->shopsForCategory(
            $request,
            (int) $intent['category_id'],
            isset($validated['lat']) ? (float) $validated['lat'] : null,
            isset($validated['lon']) ? (float) $validated['lon'] : null,
        );

        $reply = $intent['reply'];
        if ($shops->isEmpty()) {
            $name = ServiceCategories::name((int) $intent['category_id']);
            $reply = "I couldn't find any " . ($name ?? 'matching') . " shops right now. "
                . "Try again later or ask for a different service.";
        }

        return response()->json([
            'reply' => $reply,
            'category_id' => (int) $intent['category_id'],
            'shops' => $shops,
            'categories' => [],
        ]);
    }

    /**
     * Service categories that currently have at least one bookable shop, with a
     * shop count each — used for the "what can I search?" chips. No Claude call.
     */
    public function categories()
    {
        return response()->json(['categories' => $this->availableCategories()]);
    }

    /**
     * Map the message to an intent via a single Claude call. Returns
     * ['intent' => 'find'|'list'|'off_topic', 'category_id' => ?int, 'reply' => string].
     * Falls back to a safe off-topic reply if the model or JSON parse fails.
     *
     * @return array{intent: string, category_id: int|null, reply: string}
     */
    private function classify(string $message): array
    {
        $catalogue = collect(ServiceCategories::all())
            ->map(fn ($c) => "{$c['id']} = {$c['name']}")
            ->implode("\n");

        $system = <<<SYS
You are the service finder for Rezzy, an app that lists local service shops. Map a customer's message to one of these fixed service categories:

{$catalogue}

Decide the intent:
- "find" — the message is a request to find / book / get one of these services (e.g. "find a barber near me", "AC not working", "need my car washed", "haircut"). Pick the single best matching category id.
- "list" — the message asks what services / categories are available, what can be searched, or how many services there are (e.g. "what services do you have?", "what can I search?", "how many services are available?"). category_id is null.
- "off_topic" — anything else (greetings, weather, jokes, general questions). category_id is null.

Keep the reply to one short, friendly sentence:
- find: e.g. "Here are some barbers near you 👇".
- list: e.g. "Here are the services you can search 👇".
- off_topic: politely say you can only help find local services and give an example.

Respond with STRICT JSON ONLY, no markdown, no extra text:
{"intent": "find"|"list"|"off_topic", "category_id": <id 1-10 or null>, "reply": "<one short sentence>"}
SYS;

        try {
            $raw = (new ClaudeClient())->reply($system, [
                ['role' => 'user', 'content' => $message],
            ]);
            $parsed = $this->extractJson($raw);
        } catch (\Throwable $e) {
            $parsed = null;
        }

        $offTopic = [
            'intent' => 'off_topic',
            'category_id' => null,
            'reply' => 'I can only help you find local services — try "find a barber near me" or "AC repair".',
        ];

        if (!is_array($parsed)) {
            return $offTopic;
        }

        $replyText = !empty($parsed['reply']) ? (string) $parsed['reply'] : null;
        $intent = $parsed['intent'] ?? null;

        if ($intent === 'list') {
            return [
                'intent' => 'list',
                'category_id' => null,
                'reply' => $replyText ?? 'Here are the services you can search 👇',
            ];
        }

        if ($intent === 'find') {
            $categoryId = (int) ($parsed['category_id'] ?? 0);
            if (in_array($categoryId, ServiceCategories::ids(), true)) {
                return [
                    'intent' => 'find',
                    'category_id' => $categoryId,
                    'reply' => $replyText
                        ?? ('Here are some ' . ServiceCategories::name($categoryId) . ' options 👇'),
                ];
            }
        }

        // off_topic, or find without a valid category.
        if ($replyText) {
            $offTopic['reply'] = $replyText;
        }
        return $offTopic;
    }

    /** Pull the first {...} JSON object out of a model reply. */
    private function extractJson(string $raw): ?array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, count: int}>
     */
    private function availableCategories()
    {
        $counts = Shop::where('status', Shop::ACTIVE)
            ->where('is_master', false)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) as cnt')
            ->groupBy('category_id')
            ->pluck('cnt', 'category_id');

        return collect(ServiceCategories::all())
            ->filter(fn ($c) => (int) ($counts[$c['id']] ?? 0) > 0)
            ->map(fn ($c) => [
                'id' => $c['id'],
                'name' => $c['name'],
                'count' => (int) ($counts[$c['id']] ?? 0),
            ])
            ->values();
    }

    /**
     * Active shops in a category, mirroring ShopController so the payload matches
     * what the customer ShopCard renders. With coords, rank by Haversine distance
     * and attach a formatted distance string (same expression as nearby()).
     */
    private function shopsForCategory(Request $request, int $categoryId, ?float $lat, ?float $lon)
    {
        $deviceId = $request->header('X-Device-Id');
        $hasCoords = $lat !== null && $lon !== null;
        $distanceExpr = "(6371 * ACOS(LEAST(1, GREATEST(-1, COS(RADIANS(?)) * COS(RADIANS(lat)) * COS(RADIANS(lon) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(lat))))))";

        $query = Shop::query()
            ->where('status', Shop::ACTIVE)
            ->where('is_master', false)
            ->where('category_id', $categoryId);

        // Explicit selects must precede withCount so its subquery column survives.
        if ($hasCoords) {
            $query->whereNotNull('lat')
                ->whereNotNull('lon')
                ->select('shops.*')
                ->selectRaw($distanceExpr . ' as distance_km', [$lat, $lon, $lat]);
        }

        $query->withCount([
                'guest_favourites as is_favourite' => function ($q) use ($deviceId) {
                    $q->where('device_id', $deviceId);
                },
            ])
            ->with('today_working_hours');

        if ($hasCoords) {
            $query->orderByRaw($distanceExpr . ' asc', [$lat, $lon, $lat]);
        } else {
            $query->orderByDesc('is_verified')->orderByDesc('id');
        }

        $shops = $query->limit(15)->get();

        if ($hasCoords) {
            $shops->transform(function ($shop) {
                $distance = (float) ($shop->distance_km ?? 0);
                $shop->distance = number_format($distance, 1) . ' km';
                return $shop;
            });
        }

        return $shops;
    }
}
