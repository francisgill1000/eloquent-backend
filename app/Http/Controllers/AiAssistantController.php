<?php

namespace App\Http\Controllers;

use App\Ai\RezzyAssistantAgent;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiAssistantController extends Controller
{
    private const SHOP_DETAIL_URL = 'https://eloquentservice.com/detail?id=';

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message'         => 'required|string|max:2000',
            'conversation_id' => 'nullable|string|size:36',
            'user_id'         => 'nullable|string|max:64',
            'lat'             => 'nullable|numeric|between:-90,90',
            'lon'             => 'nullable|numeric|between:-180,180',
        ]);

        $message = trim($data['message']);

        if ($message === '') {
            return response()->json([
                'message' => 'Message cannot be empty.',
            ], 422);
        }

        try {
            $agent       = RezzyAssistantAgent::make();
            $userId      = is_numeric($data['user_id'] ?? null) ? (int) $data['user_id'] : null;
            $participant = (object) ['id' => $userId];

            if (! empty($data['conversation_id'])) {
                $agent->continue($data['conversation_id'], $participant);
            } else {
                $agent->forUser($participant);
            }

            $response   = $agent->prompt($message);
            $rawText    = trim((string) $response->text);
            $actionData = $this->parseActionJson($rawText);

            Log::info('AI assistant reply', [
                'message'     => $message,
                'raw_text'    => $rawText,
                'parsed'      => $actionData,
                'has_coords'  => isset($data['lat'], $data['lon']),
            ]);

            if (($actionData['action'] ?? null) === 'find_shops') {
                return $this->handleFindShops(
                    response:       $response,
                    query:          (string) ($actionData['query'] ?? ''),
                    lat:            $data['lat'] ?? null,
                    lon:            $data['lon'] ?? null,
                );
            }

            return response()->json([
                'conversation_id' => $response->conversationId,
                'action'          => 'chat',
                'reply'           => $rawText,
            ]);
        } catch (Throwable $e) {
            Log::error('AI assistant chat failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Assistant is unavailable right now. Please try again.',
            ], 500);
        }
    }

    private function handleFindShops($response, string $query, $lat, $lon): JsonResponse
    {
        $query = trim($query);

        if ($lat === null || $lon === null) {
            return response()->json([
                'conversation_id' => $response->conversationId,
                'action'          => 'needs_location',
                'query'           => $query,
                'reply'           => "I can find nearby shops for you — please allow location access in your browser, then send your message again.",
            ]);
        }

        $lat = (float) $lat;
        $lon = (float) $lon;
        $radiusKm = 10;

        // Bounding-box prefilter (~1° lat ≈ 111 km) so we don't scan every shop.
        $latDelta = $radiusKm / 111.0;
        $lonDelta = $radiusKm / max(1e-6, 111.0 * cos(deg2rad($lat)));

        $candidates = Shop::query()
            ->where('status', Shop::ACTIVE)
            ->whereNotNull('lat')
            ->whereNotNull('lon')
            ->whereBetween('lat', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('lon', [$lon - $lonDelta, $lon + $lonDelta])
            ->when($query !== '', function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('name', 'LIKE', '%' . $query . '%')
                        ->orWhere('shop_code', 'LIKE', $query . '%')
                        ->orWhere('location', 'LIKE', '%' . $query . '%')
                        ->orWhereHas('catalogs', function ($c) use ($query) {
                            $c->where('title', 'LIKE', '%' . $query . '%');
                        });
                });
            })
            ->limit(100)
            ->get();

        $shops = $this->rankByDistance($candidates, $lat, $lon, $radiusKm);
        $usedFallback = false;

        // Fallback: if the keyword filter found nothing, show the nearest shops regardless of query.
        if ($shops->isEmpty() && $query !== '') {
            $fallbackCandidates = Shop::query()
                ->where('status', Shop::ACTIVE)
                ->whereNotNull('lat')
                ->whereNotNull('lon')
                ->whereBetween('lat', [$lat - $latDelta, $lat + $latDelta])
                ->whereBetween('lon', [$lon - $lonDelta, $lon + $lonDelta])
                ->limit(100)
                ->get();

            $shops = $this->rankByDistance($fallbackCandidates, $lat, $lon, $radiusKm);
            $usedFallback = $shops->isNotEmpty();
        }

        if ($shops->isEmpty()) {
            $label = $query !== '' ? "\"{$query}\" shops" : 'shops';
            return response()->json([
                'conversation_id' => $response->conversationId,
                'action'          => 'find_shops',
                'query'           => $query,
                'shops'           => [],
                'reply'           => "I couldn't find any {$label} within {$radiusKm} km of you. Try widening the search or a different keyword.",
            ]);
        }

        $shopData = $shops->map(function ($shop) {
            return [
                'id'       => $shop->id,
                'name'     => $shop->name,
                'distance' => number_format((float) $shop->distance_km, 1) . ' km',
                'url'      => self::SHOP_DETAIL_URL . $shop->id,
            ];
        })->values();

        $lines = $shopData->map(function ($s, $i) {
            return ($i + 1) . ". [{$s['name']}]({$s['url']}) — {$s['distance']}";
        })->implode("\n");

        $heading = match (true) {
            $usedFallback        => "I didn't find an exact match for \"{$query}\", but here are the closest shops to you:",
            $query !== ''        => "Here are the closest \"{$query}\" shops to you:",
            default              => "Here are the closest shops to you:",
        };

        return response()->json([
            'conversation_id' => $response->conversationId,
            'action'          => 'find_shops',
            'query'           => $query,
            'shops'           => $shopData,
            'reply'           => "{$heading}\n\n{$lines}",
        ]);
    }

    private function rankByDistance($candidates, float $lat, float $lon, float $radiusKm)
    {
        return $candidates
            ->map(function ($shop) use ($lat, $lon) {
                $shop->distance_km = $this->haversineKm($lat, $lon, (float) $shop->lat, (float) $shop->lon);
                return $shop;
            })
            ->filter(fn ($shop) => $shop->distance_km <= $radiusKm)
            ->sortBy('distance_km')
            ->take(5)
            ->values();
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earthKm * 2 * asin(min(1.0, sqrt($a)));
    }

    private function parseActionJson(string $text): ?array
    {
        $text = trim($text);

        // Strip code fences if present.
        if (str_contains($text, '```')) {
            $text = preg_replace('/```(?:json)?\s*|```/m', '', $text);
            $text = trim($text);
        }

        // Fast path: whole string is JSON.
        $decoded = json_decode($text, true);
        if (is_array($decoded) && isset($decoded['action'])) {
            return $decoded;
        }

        // Fallback: find any {...} block that parses as JSON with an "action" key.
        if (preg_match_all('/\{[^{}]*"action"\s*:\s*"[^"]+"[^{}]*\}/s', $text, $matches)) {
            foreach ($matches[0] as $candidate) {
                $decoded = json_decode($candidate, true);
                if (is_array($decoded) && isset($decoded['action'])) {
                    return $decoded;
                }
            }
        }

        return null;
    }
}
