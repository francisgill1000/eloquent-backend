<?php

namespace App\Support\Wa;

use App\Models\Shop;
use App\Models\Staff;
use App\Support\ServiceCategories;

/**
 * Builds a complete, ready-to-edit system prompt from a shop's profile —
 * services with prices, weekly hours, staff, location and booking
 * instructions. The shop owner triggers this with the "Generate from profile"
 * button; the result is plain editable text they can keep or change. Nothing
 * here is injected behind the scenes — what the owner saves is what runs.
 */
class PromptGenerator
{
    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public static function generate(Shop $shop): string
    {
        $name = $shop->name ?: 'our business';
        $category = ServiceCategories::name($shop->category_id);
        $business = $category ? "{$name}, a " . mb_strtolower($category) . ' business' : $name;

        $lines = [];
        $lines[] = "You are the friendly, professional WhatsApp and chat assistant for {$business}.";
        if ($shop->location) {
            $lines[] = "Location: {$shop->location}.";
        }
        $lines[] = '';
        $lines[] = 'Your job: warmly help customers, answer questions about services, prices and timings, and book appointments.';
        $lines[] = '';
        $lines[] = "STYLE: Keep every reply short — 1 to 3 sentences, under 40 words. One thing at a time. Always reply in the customer's language. A warm emoji now and then is nice.";
        $lines[] = '';
        $lines[] = self::servicesBlock($shop);
        $lines[] = '';
        $lines[] = self::hoursBlock($shop);

        $staff = Staff::where('shop_id', $shop->id)->where('is_active', true)->pluck('name')->filter()->values()->all();
        if ($staff) {
            $lines[] = '';
            $lines[] = 'Team: ' . implode(', ', $staff) . '.';
        }

        $lines[] = '';
        $lines[] = 'BOOKING:';
        $lines[] = '- When a customer wants to book, check real availability for their date first and only offer free time slots.';
        $lines[] = '- Collect their full name and phone number (ask for the country code if missing).';
        $lines[] = '- Repeat the full summary — service, date, time, name, phone — and ask "Shall I confirm this booking?".';
        $lines[] = '- Only after they reply yes, create the booking, then share the exact booking reference.';
        $lines[] = '- Greet returning customers by name and never ask them to register again.';
        $lines[] = '- Never say a booking, cancellation or change is done unless the system actually confirmed it.';
        $lines[] = '';
        $lines[] = 'Only offer the services listed above, at their exact prices. Never invent services, prices or availability — if unsure, say the team will confirm shortly.';

        return implode("\n", $lines);
    }

    private static function servicesBlock(Shop $shop): string
    {
        $services = $shop->catalogs()->get(['title', 'price'])
            ->filter(fn ($s) => trim((string) $s->title) !== '');

        if ($services->isEmpty()) {
            return 'SERVICES: not published yet — ask what the customer needs and say the team will confirm the details and price.';
        }

        $items = $services->map(function ($s) {
            $price = $s->price !== null ? ' — AED ' . number_format((float) $s->price, 2) : '';
            return '- ' . trim($s->title) . $price;
        })->implode("\n");

        return "SERVICES (only offer these, with their exact prices):\n" . $items;
    }

    private static function hoursBlock(Shop $shop): string
    {
        $byDay = $shop->working_hours()->get(['day_of_week', 'start_time', 'end_time'])->keyBy('day_of_week');

        $rows = [];
        foreach (self::DAYS as $dow => $dname) {
            $h = $byDay->get($dow);
            $rows[] = $h
                ? "{$dname}: " . substr((string) $h->start_time, 0, 5) . '–' . substr((string) $h->end_time, 0, 5)
                : "{$dname}: closed";
        }

        return "OPENING HOURS (Dubai time, a missing day means closed):\n" . implode("\n", $rows);
    }
}
