<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Shop;
use App\Services\WhatsAppCloud;
use Carbon\Carbon;

/**
 * Builds and delivers the customer-facing WhatsApp booking reminder. Extracted
 * so both the scheduled command and (future) manual "remind now" actions share
 * one tenant-safe rendering + send path.
 */
class BookingReminderService
{
    /** Lead window: remind once the appointment is within this many hours. */
    public const WINDOW_HOURS = 24;

    public const DEFAULT_TEMPLATE =
        'Hi {name}, this is a reminder of your appointment at {shop} on {date} at {time}. '
        . 'Reply here to confirm or reschedule.';

    public function __construct(private WhatsAppCloud $wa)
    {
    }

    /** The appointment's absolute start moment (shop-local wall clock). */
    public function appointmentAt(Booking $booking): Carbon
    {
        $time = Carbon::parse($booking->getRawOriginal('start_time'))->format('H:i:s');
        return Carbon::parse(Carbon::parse($booking->date)->format('Y-m-d') . ' ' . $time);
    }

    /** True when the appointment is in the future and within the lead window. */
    public function isWithinReminderWindow(Booking $booking): bool
    {
        $at = $this->appointmentAt($booking);
        return $at->isFuture() && $at->lte(now()->addHours(self::WINDOW_HOURS));
    }

    /** Render the shop's template with tenant-safe values. */
    public function render(Shop $shop, Booking $booking): string
    {
        $template = trim((string) $shop->booking_reminder_template) ?: self::DEFAULT_TEMPLATE;
        $at = $this->appointmentAt($booking);

        return strtr($template, [
            '{name}'    => $booking->customer_name ?: 'there',
            '{shop}'    => $shop->name,
            '{date}'    => $at->format('D, d M Y'),
            '{time}'    => $at->format('g:i A'),
            '{service}' => $this->serviceLabel($booking),
        ]);
    }

    /** Send the reminder via the shop's own WhatsApp account. */
    public function send(Shop $shop, Booking $booking): void
    {
        $account = $shop->waAccount;
        if (! $account) {
            throw new \RuntimeException("Shop {$shop->id} has no WhatsApp account");
        }

        $this->wa->sendText($account, $booking->customer_whatsapp, $this->render($shop, $booking));
    }

    private function serviceLabel(Booking $booking): string
    {
        $services = $booking->services;
        if (! is_array($services) || empty($services)) {
            return 'your appointment';
        }

        $names = array_map(function ($s) {
            if (is_array($s)) {
                return $s['name'] ?? $s['title'] ?? null;
            }
            return is_string($s) ? $s : null;
        }, $services);

        $names = array_values(array_filter($names));

        return empty($names) ? 'your appointment' : implode(', ', $names);
    }
}
