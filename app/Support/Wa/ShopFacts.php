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
            . 'If a customer asks for anything not listed, or for a booking confirmation, say the team will confirm it shortly. '
            . 'Never promise a specific free slot — suggest times within opening hours and say it will be confirmed.';

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
