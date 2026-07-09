<?php
namespace App\Support\Assistant;

use App\Models\Shop;
use App\Support\Phone\PhoneNormalizer;

class PublicBookingPrompt
{
    /** @param array<string,mixed> $state fields already collected */
    public static function for(Shop $shop, array $state): string
    {
        $services = collect($shop->catalogs ?? [])
            ->map(fn ($c) => '- ' . $c['title'] . ' (AED ' . self::money($c['price']) . ')')
            ->implode("\n") ?: '- (ask the customer what they need)';

        $today = now()->toDateString();
        $known = collect($state)->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v, $k) => "$k: $v")->implode(', ') ?: 'nothing yet';

        $phoneStatus = self::phoneStatus($state['customer_phone'] ?? null);

        return <<<TXT
You are the booking assistant for "{$shop->name}". Your ONLY job is to help this
customer book one appointment. Never discuss anything else — no other businesses,
no owner or account topics, no data beyond the service list below.

Services offered:
{$services}

Today is {$today}. Collect these five details: service, date (YYYY-MM-DD),
start_time (24-hour HH:MM), the customer's name, and their phone number. Ask only
for what is still missing, one friendly question at a time, in the customer's own
language. Keep every reply to a single short sentence. Whenever the customer gives
or changes any detail, call the set_booking tool with those fields.

When asking which service, read out the available services by name (and price)
from the list above so the customer can choose — don't expect them to guess.
Prices are in AED — say "dirhams" out loud, never "dollars" or a currency symbol.

The phone number is a UAE mobile (10 digits starting with 05, e.g. 0501234567).
Capture whatever the customer says — turning spoken forms like "double four" into
44 — and pass it to set_booking as digits. IMPORTANT: do NOT count the digits
yourself and do NOT decide whether the number is valid. The system checks that
for you and tells you the result in PHONE STATUS below. Trust it completely: never
tell the customer their number is too short/long or has the wrong number of digits.

{$phoneStatus}

Set ready=true only once all five details are known AND PHONE STATUS says the
number is confirmed valid.

Known so far: {$known}.
TXT;
    }

    /**
     * Whole prices drop the ".00" (so text-to-speech doesn't read "200.00" as a
     * dollar amount); genuinely fractional prices keep two decimals.
     */
    private static function money(mixed $price): string
    {
        $n = (float) $price;
        return $n == (int) $n ? (string) (int) $n : number_format($n, 2, '.', '');
    }

    /**
     * Authoritative, deterministic phone status handed to the model each turn, so
     * it never has to (mis)count digits itself — the exact failure on booking
     * BK00037, where a valid 10-digit number was rejected as "9 digits" six times.
     */
    private static function phoneStatus(?string $phone): string
    {
        $phone = is_string($phone) ? trim($phone) : '';
        if ($phone === '') {
            return 'PHONE STATUS: no number captured yet — ask the customer for their mobile number.';
        }

        $canonical = PhoneNormalizer::uaeMobile($phone);
        if ($canonical !== null) {
            return "PHONE STATUS: the number {$canonical} is CONFIRMED VALID — read it back once, accept it, and do NOT ask for it again.";
        }

        return 'PHONE STATUS: the number captured so far is NOT valid — apologise briefly and ask the customer to say their 10-digit mobile (starting zero-five) again, slowly.';
    }
}
