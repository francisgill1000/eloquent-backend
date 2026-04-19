<?php

namespace App\Http\Controllers;

use App\Ai\RezzyAssistantAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiAssistantController extends Controller
{
    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message'         => 'required|string|max:2000',
            'conversation_id' => 'nullable|string|size:36',
            'user_id'         => 'nullable|string|max:64',
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

            $response = $agent->prompt($message);

            return response()->json([
                'conversation_id' => $response->conversationId,
                'reply'           => $response->text,
            ]);
        } catch (Throwable $e) {
            Log::error('AI assistant chat failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Assistant is unavailable right now. Please try again.',
            ], 500);
        }
    }
}
