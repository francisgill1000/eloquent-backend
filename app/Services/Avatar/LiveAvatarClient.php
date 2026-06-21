<?php

namespace App\Services\Avatar;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the LiveAvatar REST API. Brokers a FULL-mode session token so
 * the browser never sees our API key; the Web SDK performs the actual start with
 * the returned session_token.
 *
 * The avatar's "brain" is our custom-LLM bridge (llm_configuration_id). Per-session
 * shop/device identity rides in dynamic_variables.session, which LiveAvatar
 * substitutes into the Context's {{session}} placeholder so the bridge can read
 * it back out of the system message.
 */
class LiveAvatarClient
{
    /**
     * @param array{avatar_id: string, voice_id: string, context_id: string, session_token: string, language?: string} $opts
     * @return array<string, mixed> the `data` object: { session_id, session_token }
     */
    public function createSession(array $opts): array
    {
        return $this->request()->post('/v1/sessions/token', [
            'mode'       => 'FULL',
            'avatar_id'  => $opts['avatar_id'],
            'avatar_persona' => [
                'voice_id'   => $opts['voice_id'],
                'context_id' => $opts['context_id'],
                'language'   => $opts['language'] ?? 'en',
            ],
            'llm_configuration_id' => config('services.liveavatar.llm_config_id'),
            'interactivity_type'   => 'CONVERSATIONAL',
            'dynamic_variables'    => ['session' => $opts['session_token']],
        ])->throw()->json('data') ?? [];
    }

    private function request(): PendingRequest
    {
        $key = config('services.liveavatar.api_key');
        if (empty($key)) {
            throw new RuntimeException('LiveAvatar is not configured (LIVEAVATAR_API_KEY missing).');
        }

        return Http::withHeaders(['X-API-KEY' => $key])
            ->baseUrl(rtrim((string) config('services.liveavatar.base_url'), '/'))
            ->acceptJson()
            ->timeout(30);
    }
}
