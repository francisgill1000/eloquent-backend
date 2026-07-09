<?php
namespace App\Support\Phone;

/**
 * Deterministic UAE-mobile parsing. The booking assistant transcribes spoken
 * numbers ("oh five … double four …"), so the LLM turns speech into a rough
 * string — but it must NOT be trusted to *count* the digits (it mis-counted a
 * valid 10-digit number as 9, six times, on booking BK00037). Counting and
 * validation live here, in code, once and correctly.
 */
class PhoneNormalizer
{
    private const WORDS = [
        'zero' => '0', 'oh' => '0',
        'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4',
        'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9',
    ];

    /**
     * Parse any spoken/typed form into a canonical UAE mobile (05XXXXXXXX),
     * or null if it isn't a valid one.
     */
    public static function uaeMobile(string $raw): ?string
    {
        $s = mb_strtolower($raw);

        // Spelled-out digits → figures (keep "double"/"triple" markers for now).
        foreach (self::WORDS as $word => $digit) {
            $s = preg_replace('/\b' . $word . '\b/', $digit, $s);
        }

        // "double four" → 44, "triple five" → 555.
        $s = preg_replace_callback('/\bdouble\s*(\d)/', fn ($m) => str_repeat($m[1], 2), $s);
        $s = preg_replace_callback('/\btriple\s*(\d)/', fn ($m) => str_repeat($m[1], 3), $s);

        $digits = preg_replace('/\D+/', '', $s);

        // Fold country-code forms to a local 0-prefixed number.
        if (str_starts_with($digits, '00971')) {
            $digits = '0' . substr($digits, 5);
        } elseif (str_starts_with($digits, '971')) {
            $digits = '0' . substr($digits, 3);
        }

        return preg_match('/^05\d{8}$/', $digits) ? $digits : null;
    }
}
