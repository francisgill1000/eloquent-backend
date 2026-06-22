<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\Wa\ClaudeClient;
use App\Support\ServiceCategories;
use Illuminate\Http\Request;

/**
 * Natural-language service finder for the customer app. One cheap Claude call
 * classifies the customer's message into one of the fixed service categories
 * (or marks it off-topic), then we return the matching shops in the SAME shape
 * the customer ShopCard already consumes (see ShopController::index/nearby).
 * Text-only; no booking tools, no persistence.
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

        // Off-topic, or no usable category — reply only, no shops.
        if (!$intent['on_topic'] || !$intent['category_id']) {
            return response()->json([
                'reply' => $intent['reply'],
                'category_id' => null,
                'shops' => [],
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
        ]);
    }

    /**
     * Map the message to a service category (or off-topic) via a single Claude
     * call. Returns ['on_topic' => bool, 'category_id' => ?int, 'reply' => string].
     * Falls back to a safe off-topic reply if the model or JSON parse fails.
     *
     * @return array{on_topic: bool, category_id: int|null, reply: string}
     */
    private function classify(string $message): array
    {
        $catalogue = collect(ServiceCategories::all())
            ->map(fn ($c) => "{$c['id']} = {$c['name']}")
            ->implode("\n");

        $system = <<<SYS
You are the service finder for Rezzy, an app that lists local service shops. Your ONLY job is to map a customer's message to one of these fixed service categories:

{$catalogue}

Rules:
- If the message is a request to find / book / get one of these services (e.g. "find a barber near me", "AC not working", "need my car washed", "haircut"), pick the single best matching category id.
- If the message is NOT about finding one of these services (greetings, weather, jokes, general questions, anything off-topic), it is off-topic.
- Keep the reply to one short, friendly sentence. For on-topic, say something like "Here are some barbers near you 👇". For off-topic, politely say you can only help find local services and give an example.
- Respond with STRICT JSON ONLY, no markdown, no extra text:
{"on_topic": true|false, "category_id": <id 1-10 or null>, "reply": "<one short sentence>"}
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
            'on_topic' => false,
            'category_id' => null,
            'reply' => 'I can only help you find local services — try "find a barber near me" or "AC repair".',
        ];

        // Parse failed, or the model said off-topic: keep the model's own reply
        // when it gave one, otherwise the canned fallback.
        if (!is_array($parsed) || empty($parsed['on_topic'])) {
            if (is_array($parsed) && !empty($parsed['reply'])) {
                $offTopic['reply'] = (string) $parsed['reply'];
            }
            return $offTopic;
        }

        $categoryId = (int) ($parsed['category_id'] ?? 0);
        if (!in_array($categoryId, ServiceCategories::ids(), true)) {
            return $offTopic;
        }

        return [
            'on_topic' => true,
            'category_id' => $categoryId,
            'reply' => !empty($parsed['reply'])
                ? (string) $parsed['reply']
                : 'Here are some ' . ServiceCategories::name($categoryId) . ' options 👇',
        ];
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
