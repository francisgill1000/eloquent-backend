<?php

namespace App\Services\Avatar;

use InvalidArgumentException;

/**
 * Opaque signed token that carries the per-session context (shop id + device
 * id) for the avatar. It is embedded in the LiveAvatar session's system prompt
 * at mint time and parsed back by the custom-LLM bridge, so we never trust
 * client-supplied shop identity and never need per-shop LLM configs.
 */
class AvatarSessionToken
{
    public const MARKER = '[[avatar-session:%s]]';

    public static function issue(int $shopId, string $deviceId): string
    {
        $payload = self::b64(json_encode(['s' => $shopId, 'd' => $deviceId]));

        return $payload . '.' . self::sign($payload);
    }

    /** @return array{shop_id: int, device_id: string} */
    public static function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! hash_equals(self::sign($parts[0]), $parts[1])) {
            throw new InvalidArgumentException('Invalid avatar session token.');
        }

        $data = json_decode(self::unb64($parts[0]), true);
        if (! is_array($data) || ! isset($data['s'], $data['d'])) {
            throw new InvalidArgumentException('Malformed avatar session token.');
        }

        return ['shop_id' => (int) $data['s'], 'device_id' => (string) $data['d']];
    }

    public static function extractFromText(string $text): ?string
    {
        if (preg_match('/\[\[avatar-session:([^\]]+)\]\]/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function sign(string $payload): string
    {
        $secret = (string) config('services.liveavatar.session_secret');

        return self::b64(hash_hmac('sha256', $payload, $secret, true));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $enc): string
    {
        return base64_decode(strtr($enc, '-_', '+/'));
    }
}
