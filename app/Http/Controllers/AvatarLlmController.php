<?php

namespace App\Http\Controllers;

use App\Services\Avatar\AvatarBrain;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * OpenAI-compatible custom-LLM endpoint that LiveAvatar calls each user turn.
 * Runs the Rezzy brain server-side and streams the final reply back as a single
 * chat.completion.chunk followed by [DONE]. Tool calls (if any) run inside the
 * brain; LiveAvatar only ever receives the final spoken text.
 */
class AvatarLlmController extends Controller
{
    public function completions(Request $request, AvatarBrain $brain): StreamedResponse
    {
        try {
            $text = $brain->answer((array) $request->input('messages', []));
        } catch (\InvalidArgumentException $e) {
            // Fail fast (no streaming) when the session token is missing/bad.
            abort(400, $e->getMessage());
        }

        return response()->stream(function () use ($text) {
            $chunk = [
                'id'      => 'chatcmpl-avatar',
                'object'  => 'chat.completion.chunk',
                'choices' => [[
                    'index'         => 0,
                    'delta'         => ['role' => 'assistant', 'content' => $text],
                    'finish_reason' => null,
                ]],
            ];
            echo 'data: ' . json_encode($chunk) . "\n\n";

            $done = [
                'id'      => 'chatcmpl-avatar',
                'object'  => 'chat.completion.chunk',
                'choices' => [[
                    'index'         => 0,
                    'delta'         => new \stdClass(),
                    'finish_reason' => 'stop',
                ]],
            ];
            echo 'data: ' . json_encode($done) . "\n\n";
            echo "data: [DONE]\n\n";
            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
