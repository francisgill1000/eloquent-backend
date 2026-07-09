<?php
namespace App\Support\Assistant;

use App\Models\Shop;

class PublicBookingPrompt
{
    /** @param array<string,mixed> $state fields already collected */
    public static function for(Shop $shop, array $state): string
    {
        $services = collect($shop->catalogs ?? [])
            ->map(fn ($c) => '- ' . $c['title'] . ' (AED ' . $c['price'] . ')')
            ->implode("\n") ?: '- (ask the customer what they need)';

        $today = now()->toDateString();
        $known = collect($state)->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v, $k) => "$k: $v")->implode(', ') ?: 'nothing yet';

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
or changes any detail, call the set_booking tool with those fields. Set ready=true
only once all five details are known.

Known so far: {$known}.
TXT;
    }
}
