<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Shop;
use App\Services\WhatsAppCloud;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Notifies a waitlisted (queued) customer over WhatsApp when their booking is
 * promoted to booked. Failure-isolated: a send error is logged and never breaks
 * the promotion. Tenant-safe (each shop's own account + name).
 */
class WaitlistNotifier
{
    public const DEFAULT_TEMPLATE =
        'Good news {name}! A slot opened up at {shop} and your booking {reference} '
        . 'is confirmed for {date} at {time}. See you then!';

    public function __construct(private WhatsAppCloud $wa)
    {
    }

    public function notifyPromoted(Booking $booking): void
    {
        try {
            $shop = $booking->shop;
            if (! $shop || ! $shop->waitlist_notify_enabled) {
                return;
            }
            if (empty($booking->customer_whatsapp)) {
                return;
            }
            $account = $shop->waAccount;
            if (! $account) {
                return;
            }

            $this->wa->sendText($account, $booking->customer_whatsapp, $this->render($shop, $booking));
        } catch (\Throwable $e) {
            Log::warning('waitlist notify failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function render(Shop $shop, Booking $booking): string
    {
        $template = trim((string) $shop->waitlist_notify_template) ?: self::DEFAULT_TEMPLATE;
        $at = Carbon::parse(Carbon::parse($booking->date)->format('Y-m-d') . ' '
            . Carbon::parse($booking->getRawOriginal('start_time'))->format('H:i:s'));

        return strtr($template, [
            '{name}'      => $booking->customer_name ?: 'there',
            '{shop}'      => $shop->name,
            '{reference}' => $booking->booking_reference,
            '{date}'      => $at->format('D, d M Y'),
            '{time}'      => $at->format('g:i A'),
        ]);
    }
}
