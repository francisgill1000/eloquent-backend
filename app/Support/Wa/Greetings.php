<?php

namespace App\Support\Wa;

/**
 * Detect bare greetings ("hi", "hello", "good morning", "salam", ...) —
 * messages with no actual question. These get an instant canned welcome
 * instead of a Claude call. Ported from whatsapp-autoreply/lib/greetings.js.
 */
class Greetings
{
    private const GREETINGS = [
        // collapsed forms (repeated letters are squashed before matching)
        'h', 'hi', 'hy', 'hey', 'helo', 'hello', 'hiya', 'hola', 'hallo', 'yo',
        'hi there', 'hello there', 'hey there', 'greetings', 'start', 'namaste',
        'salam', 'salam alaikum', 'asalam', 'asalam alaikum', 'asalamualaikum',
        'good morning', 'good afternoon', 'good evening', 'good day', 'gm', 'ge',
    ];

    public static function isBare(?string $text): bool
    {
        if (!is_string($text) || $text === '') {
            return false;
        }

        // Keep only letters/numbers/spaces (drops emojis, punctuation), lowercase.
        $s = mb_strtolower($text);
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if ($s === '') {
            return false;
        }

        // Squash runs of the same letter: "hiii"→"hi", "helloooo"→"helo".
        $collapsed = preg_replace('/(\p{L})\1+/u', '$1', $s);

        return in_array($s, self::GREETINGS, true) || in_array($collapsed, self::GREETINGS, true);
    }
}
