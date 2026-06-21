<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_KEY'),
    ],

    'whatsapp' => [
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'graph_version' => env('GRAPH_API_VERSION', 'v25.0'),
        // Shared system-user token for all numbers under our own WABA.
        // Per-account tokens (wa_accounts.token) override this when set.
        'default_token' => env('WHATSAPP_DEFAULT_TOKEN'),
        // Meta app secret — verifies X-Hub-Signature-256 on webhooks (no-op when unset).
        'app_secret' => env('WHATSAPP_APP_SECRET'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-haiku-4-5'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'), // Whisper + TTS; absent → voice features off
        'tts_model' => env('TTS_MODEL', 'gpt-4o-mini-tts'),
        'tts_voice' => env('TTS_VOICE', 'nova'),
    ],

    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@eloquentservice.com'),
    ],

    'ziina' => [
        // Bearer token from https://ziina.com/business/connect
        'api_key'        => env('ZIINA_API_KEY'),
        // Shared secret used to sign webhooks (X-Hmac-Signature). Optional but recommended.
        'webhook_secret' => env('ZIINA_WEBHOOK_SECRET'),
        'base_url'       => env('ZIINA_BASE_URL', 'https://api-v2.ziina.com/api'),
        // true → test transactions, no real charge. Flip to false to go live.
        'test'           => env('ZIINA_TEST', true),
        // Where Ziina sends customers back; falls back to APP_URL when unset.
        'return_base'    => env('ZIINA_RETURN_BASE', env('CUSTOMER_APP_URL')),
    ],

    // LiveAvatar (HeyGen) FULL-mode talking avatar — "Video Assistant".
    // The API key/session secret never reach the browser; the backend brokers
    // the session and runs the existing Rezzy brain via a custom-LLM bridge.
    'liveavatar' => [
        'api_key'           => env('LIVEAVATAR_API_KEY'),
        'base_url'          => env('LIVEAVATAR_BASE_URL', 'https://api.liveavatar.com'),
        // One global custom-LLM configuration id (created once in the dashboard).
        'llm_config_id'     => env('LIVEAVATAR_LLM_CONFIG_ID'),
        // HMAC secret for the signed per-session context token.
        'session_secret'    => env('LIVEAVATAR_SESSION_SECRET'),
        // Fallback avatar/voice when a shop has none set.
        'default_avatar_id' => env('LIVEAVATAR_DEFAULT_AVATAR_ID'),
        'default_voice_id'  => env('LIVEAVATAR_DEFAULT_VOICE_ID'),
    ],

];
