<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestClaude extends Command
{
    protected $signature = 'claude:test {message}';
    protected $description = 'Test Claude AI from terminal';

    public function handle()
    {
        $message = $this->argument('message');

        $response = Http::withHeaders([
            'x-api-key' => env('ANTHROPIC_API_KEY'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [

            "model" => "claude-sonnet-4-6",
            "max_tokens" => 1024,

            "messages" => [
                [
                    "role" => "user",
                    "content" => $message
                ]
            ]

        ]);

        $data = $response->json();

        $this->info($data['content'][0]['text'] ?? 'No response');
    }
}
