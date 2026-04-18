<?php

namespace App\Http\Controllers;

use App\Ai\VoiceIntentAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class VoiceIntentController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text'            => 'required|string|max:500',
            'conversation_id' => 'nullable|string|size:36',
            'user_id'         => 'nullable|string|max:64',
        ]);

        $transcript = trim($data['text']);
        $userId     = is_numeric($data['user_id'] ?? null) ? (int) $data['user_id'] : null;

        if ($transcript === '') {
            return response()->json($this->noneResponse(''));
        }

        try {
            $agent = VoiceIntentAgent::make();

            $participant = (object) ['id' => $userId];

            if (! empty($data['conversation_id'])) {
                $agent->continue($data['conversation_id'], $participant);
            } else {
                $agent->forUser($participant);
            }

            $response = $agent->prompt($transcript);

            $parsed = $this->parseJson($response->text);

            return response()->json([
                'transcript'      => $transcript,
                'conversation_id' => $response->conversationId,
                'action'          => $parsed['action'] ?? 'none',
                'query'           => $parsed['query']  ?? '',
                'screen'          => $parsed['screen'] ?? '',
                'reply'           => $parsed['reply']  ?? "Sorry, I didn't catch that.",
            ]);
        } catch (Throwable $e) {
            Log::error('Voice intent failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Voice processing failed. Please try again.',
            ], 500);
        }
    }

    private function parseJson(string $text): array
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
        }

        return json_decode($text, true) ?: [];
    }

    private function noneResponse(string $transcript): array
    {
        return [
            'transcript'      => $transcript,
            'conversation_id' => null,
            'action'          => 'none',
            'query'           => '',
            'screen'          => '',
            'reply'           => "Sorry, I didn't catch that. Please try again.",
        ];
    }
}
