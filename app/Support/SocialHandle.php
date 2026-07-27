<?php

namespace App\Support;

/**
 * Turns whatever someone pastes — @handle, bare handle, or a URL in any of its
 * usual shapes — into one canonical link per platform, so the UI can always
 * link out and two spellings of the same profile never look like two profiles.
 *
 * Pure: no I/O, no models, no config. Cross-platform input is rejected rather
 * than coerced — an Instagram URL in the LinkedIn field is a mistake worth
 * surfacing, not a link worth mislabelling.
 */
class SocialHandle
{
    public const PLATFORMS = ['instagram', 'facebook', 'tiktok', 'linkedin', 'email'];

    /** Hosts that identify a platform. Subdomains (www., m.) are matched too. */
    private const HOSTS = [
        'instagram' => ['instagram.com'],
        'facebook'  => ['facebook.com', 'fb.com'],
        'tiktok'    => ['tiktok.com'],
        'linkedin'  => ['linkedin.com'],
    ];

    /** Platforms whose path carries meaning (/in/x vs /company/x) and is kept whole. */
    private const PATH_PLATFORMS = ['facebook', 'linkedin'];

    public static function normalize(string $platform, string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || ! in_array($platform, self::PLATFORMS, true)) {
            return null;
        }

        if ($platform === 'email') {
            $email = strtolower($input);
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        }

        $detected = self::detectPlatform($input);
        if ($detected !== null) {
            // A known platform host: it must be THIS platform's host.
            return $detected === $platform ? self::fromUrl($platform, $input) : null;
        }

        // No recognisable platform host. Anything with a scheme or a slash is
        // some other site's URL, not a handle — reject rather than mangle.
        if (str_contains($input, '/')) {
            return null;
        }

        return self::fromHandle($platform, ltrim($input, '@'));
    }

    /** The platform a string's host belongs to, or null when it isn't one of ours. */
    public static function detectPlatform(string $input): ?string
    {
        $host = strtolower(trim($input));
        $host = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $host);
        $host = explode('/', $host)[0];

        foreach (self::HOSTS as $platform => $hosts) {
            foreach ($hosts as $known) {
                if ($host === $known || str_ends_with($host, '.' . $known)) {
                    return $platform;
                }
            }
        }

        return null;
    }

    private static function fromUrl(string $platform, string $url): ?string
    {
        if (! preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return null;
        }

        if (in_array($platform, self::PATH_PLATFORMS, true)) {
            $query = ($parts['query'] ?? '') !== '' ? '?' . $parts['query'] : '';
            return 'https://' . self::HOSTS[$platform][0] . '/' . $path . $query;
        }

        $handle = ltrim(explode('/', $path)[0], '@');

        return $handle === '' ? null : self::url($platform, $handle);
    }

    private static function fromHandle(string $platform, string $handle): ?string
    {
        if (! preg_match('/^[A-Za-z0-9._-]{1,100}$/', $handle)) {
            return null;
        }

        return self::url($platform, $handle);
    }

    private static function url(string $platform, string $handle): string
    {
        return match ($platform) {
            'instagram' => "https://instagram.com/{$handle}",
            'facebook'  => "https://facebook.com/{$handle}",
            'tiktok'    => "https://tiktok.com/@{$handle}",
            'linkedin'  => "https://linkedin.com/company/{$handle}",
        };
    }
}
