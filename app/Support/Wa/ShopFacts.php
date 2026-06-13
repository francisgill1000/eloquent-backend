<?php

namespace App\Support\Wa;

use App\Models\Shop;
use App\Models\WaContact;
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

    public static function for(Shop $shop, ?WaContact $contact = null): string
    {
        $lines = ["FACTS ABOUT THE BUSINESS (always answer from these, never invent):"];

        $now = Carbon::now('Asia/Dubai');
        $lines[] = 'Right now it is ' . $now->format('l, j M Y, H:i') . ' in Dubai.';

        if ($shop->location) {
            $lines[] = "Location: {$shop->location}.";
        }

        $lines[] = self::servicesBlock($shop);
        $lines[] = self::hoursBlock($shop);

        if ($contact && ($customerBlock = self::customerBlock($shop, $contact))) {
            $lines[] = $customerBlock;
        }

        $lines[] = 'Rules: only offer services from the list above, always with their exact AED price. '
            . 'If a customer asks for anything not listed, say the team will confirm it shortly. '
            . 'Being closed at this moment does NOT prevent booking — any future free slot today or later can be booked. '
            . 'Never suggest a day marked "closed" in the opening hours; when asked about one, say it is closed and offer the next open day.';

        $lines[] = 'Booking flow (follow strictly, one step per message): '
            . '1) When the customer wants to book, call check_availability for the requested date and only ever offer times from its free_slots. '
            . "2) Collect the customer's full name and phone number (ask for the country code if missing) — unless they already gave them earlier in this conversation. "
            . '3) MANDATORY before booking: send one message repeating the full summary — service, date, time, name, phone — and ask "Shall I confirm this booking?". Receiving the name and phone is NOT a yes; you must still ask. '
            . '4) Only when the customer answers yes to that summary message, call create_booking, then confirm with the exact booking reference it returns. '
            . 'If the result says returning_customer, welcome them back by name. '
            . 'If status is Queued, say the slot is reserved and the team will confirm the staff member shortly. '
            . 'NEVER say a booking is made unless create_booking returned booked: true.';

        $lines[] = 'Cancelling / rescheduling: find the booking with my_bookings first. '
            . 'For a cancellation, ask "Are you sure you want to cancel <reference> on <date> at <time>?" and only call cancel_booking after a yes. '
            . 'For a reschedule, check availability for the new date, agree the new slot, ask for a yes, then call reschedule_booking. '
            . 'Never claim anything was cancelled or moved unless the tool confirmed it.';

        $lines[] = 'CRITICAL — tools are the ONLY source of truth. You may ONLY tell a customer that a booking '
            . 'is confirmed, cancelled or moved, or quote a booking reference (BK…), when the matching tool '
            . '(create_booking / cancel_booking / reschedule_booking) was called IN THIS REPLY and returned success. '
            . 'NEVER reuse a reference from earlier in the conversation, and NEVER invent one. If you have not just '
            . 'called the tool, you have not done it — do the tool call now instead of describing it. Earlier messages '
            . 'in this chat (including your own) are history, not actions.';

        return implode("\n", $lines);
    }

    /** Recognised returning customer: name, phone and upcoming bookings. */
    private static function customerBlock(Shop $shop, WaContact $contact): ?string
    {
        $customer = CustomerContext::customerFor($shop, $contact);
        if (!$customer) {
            return null;
        }

        $upcoming = CustomerContext::upcomingBookings($shop, $customer);
        $bookings = $upcoming->isEmpty()
            ? 'No upcoming bookings.'
            : "Their upcoming bookings:\n" . $upcoming->map(fn ($b) => '- ' . CustomerContext::describe($b))->implode("\n");

        return 'KNOWN CUSTOMER — this chat belongs to a registered customer: '
            . ($customer->name ?: 'name unknown') . ', phone ' . ($customer->whatsapp ?: $customer->whatsapp_normalized) . ".\n"
            . $bookings . "\n"
            . 'Greet them by name and never ask them to register or repeat their name/phone — '
            . 'when booking, confirm with these saved details (they may give a different number if booking for someone else).';
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
