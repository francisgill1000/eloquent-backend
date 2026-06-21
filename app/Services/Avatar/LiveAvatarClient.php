<?php

namespace App\Services\Avatar;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the LiveAvatar REST API. Brokers a FULL-mode session so the
 * browser never sees our API key. Custom-LLM routing is fixed at the (global)
 * llm_configuration_id; per-session context travels via the system prompt.
 *
 * NOTE: endpoint paths/field names follow the documented FULL-mode token+start
 * flow and are reconciled against the live service during manual e2e. Keep all
 * LiveAvatar-specific shapes inside this class so adjustments stay localized.
 */
class LiveAvatarClient
{
    /**
     * @param array{avatar_id: string, voice_id: string, system_prompt: string} $opts
     * @return array<string, mixed> raw LiveAvatar session credentials
     */
    public function createSession(array $opts): array
    {
        $token = $this->request()->post('/v1/sessions/token', [
            'mode' => 'FULL',
        ])->throw()->json('token');

        return $this->request()->post('/v1/sessions/start', [
            'token'                => $token,
            'avatar_id'            => $opts['avatar_id'],
            'voice_id'             => $opts['voice_id'],
            'llm_configuration_id' => config('services.liveavatar.llm_config_id'),
            'system_prompt'        => $opts['system_prompt'],
            'interactivity'        => 'conversational',
        ])->throw()->json();
    }

    private function request(): PendingRequest
    {
        $key = config('services.liveavatar.api_key');
        if (empty($key)) {
            throw new RuntimeException('LiveAvatar is not configured (LIVEAVATAR_API_KEY missing).');
        }

        return Http::withToken($key)
            ->baseUrl(rtrim((string) config('services.liveavatar.base_url'), '/'))
            ->acceptJson()
            ->timeout(30);
    }
}
