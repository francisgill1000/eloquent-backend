<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class Notify
{
    public static function push($shopId, $type, $message, $data = [])
    {
        $url = "http://localhost:5000/notify"; // Node.js SSE server

        Http::post($url, [
            'clientId' => $shopId,
            'type' => $type,
            'message' => $message,
            'timestamp' => now()->toDateTimeString(),
            "data" =>  $data, // Additional data can be included here
        ]);
    }
}
