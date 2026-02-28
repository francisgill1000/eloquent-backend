<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Notify
{
    public static function push($shopId, $type, $message, $data = [])
    {
        // $url = "http://localhost:5000/notify"; // Node.js SSE server
        $url = "https://push.eloquentservice.com/notify"; // Node.js SSE server

        // map logical types to color values (frontend can use these to style the panel)
        $typeColorMap = [
            'success' => '#10B981', // green
            'info' => '#3B82F6', // blue
            'warning' => '#F59E0B', // amber
            'error' => '#EF4444', // red
        ];

        // allow overriding color via $data['color']
        $color = $data['color'] ?? ($typeColorMap[$type] ?? $typeColorMap['info']);

        $payload = [
            'clientId' => $shopId,
            'type' => $type,
            'variant' => $type, // keeps original semantic type
            'color' => $color,
            'message' => $message,
            'timestamp' => now()->toDateTimeString(),
            'data' => $data, // Additional data can be included here
        ];

        try {
            $response = Http::withoutVerifying()->post($url, $payload);

            if ($response->successful()) {
                Log::info(json_encode([
                    'url' => $url,
                    'payload' => $payload,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'json' => $response->json(),
                ], JSON_PRETTY_PRINT));
            } else {
                Log::warning(json_encode([
                    'url' => $url,
                    'payload' => $payload,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'json' => $response->json(),
                ], JSON_PRETTY_PRINT));
            }
        } catch (\Throwable $e) {
            Log::error(json_encode([
                'url' => $url,
                'payload' => $payload,
                'error' => $e->getMessage(),
            ], JSON_PRETTY_PRINT));
        }
    }
}
