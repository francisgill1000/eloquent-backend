<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;

class ClaudeController extends Controller
{
    public function chat()
    {

        $response = Http::withHeaders([
            'x-api-key' => env('ANTHROPIC_API_KEY','myapkkey'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [

            "model" => "claude-sonnet-4-6",
            "max_tokens" => 1024,

            "messages" => [
                [
                    "role" => "user",
                    "content" => "Hello, Claude"
                ]
            ]

        ]);

        $data = $response->json();

        return $data['content'][0]['text'];
    }
}