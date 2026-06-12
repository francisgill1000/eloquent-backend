<?php

namespace App\Support\Wa;

use App\Models\Shop;
use Illuminate\Support\Carbon;

/**
 * Live business facts appended to every assistant system prompt so the AI
 * answers from the shop's real data instead of guessing: services with exact
 * prices, weekly hours, location and the current Dubai time (for "today /
 * tomorrow" questions). Missing working-hours rows mean the shop is closed
 * that day (day_of_week: Sun=0 … Sat=6).
 */
class ShopFacts
{
    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    private const MAX_SERVICES = 40;

    public static function for(Shop $shop): string
    {
        $lines = ["FACTS ABOUT THE BUSINESS (always answer from these, never invent):"];

        $now = Carbon::now('Asia/Dubai');
        $lines[] = 'Right now it is ' . $now->format('l, j M Y, H:i') . ' in Dubai.';

        if ($shop->location) {
            $lines[] = "Location: {$shop->location}.";
        }

        $lines[] = self::servicesBlock($shop);
        $lines[] = self::hoursBlock($shop);

        $lines[] = 'Rules: only offer services from the list above, always with their exact AED price. '
            . 'If a customer asks for anything not listed, say the team will confirm it shortly.';

        $lines[] = 'Booking flow (follow strictly, one step per message): '
            . '1) When the customer wants to book, call check_availability for the requested date and only ever offer times from its free_slots. '
            . "2) Collect the customer's full name and phone number (ask for the country code if missing) — unless they already gave them earlier in this conversation. "
            . '3) Repeat the full summary — service, date, time, name, phone — and ask for a clear yes. '
            . '4) Only after that yes, call create_booking, then confirm with the exact booking reference it returns. '
            . 'If the result says returning_customer, welcome them back by name. '
            . 'If status is Queued, say the slot is reserved and the team will confirm the staff member shortly. '
            . 'NEVER say a booking is made unless create_booking returned booked: true.';

        return implode("\n", $lines);
    }

    private static function servicesBlock(Shop $shop): string
    {
        $services = $shop->catalogs()
            ->get(['title', 'description', 'price'])
            ->filter(fn ($s) => trim((string) $s->title) !== '')
            ->take(self::MAX_SERVICES);

        if ($services->isEmpty()) {
            return 'Services: the service list is not published yet — ask what the customer needs and say the team will confirm details and prices.';
        }

        $items = $services->map(function ($s) {
            $price = $s->price !== null ? ' — AED ' . number_format((float) $s->price, 2) : '';
            return '- ' . trim($s->title) . $price;
        })->implode("\n");

        return "Services and prices (complete list):\n" . $items;
    }

    private static function hoursBlock(Shop $shop): string
    {
        $byDay = $shop->working_hours()->get(['day_of_week', 'start_time', 'end_time'])
            ->keyBy('day_of_week');

        $rows = [];
        foreach (self::DAYS as $dow => $name) {
            $h = $byDay->get($dow);
            $rows[] = $h
                ? "{$name}: " . substr((string) $h->start_time, 0, 5) . '–' . substr((string) $h->end_time, 0, 5)
                : "{$name}: closed";
        }

        return "Opening hours:\n" . implode("\n", $rows);
    }
}
