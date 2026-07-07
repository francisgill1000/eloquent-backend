<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Shop;
use App\Services\Booking\BookingReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sends a customer-facing WhatsApp reminder for booked appointments that are
 * entering the 24-hour lead window. Runs hourly. Fully tenant-scoped: each
 * shop's own WaAccount + template is used, and the not-yet-sent flag makes it
 * idempotent so hourly runs never double-send.
 *
 * No payment/charge code — pure messaging on the existing WhatsApp Cloud stack.
 */
class SendBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders';

    protected $description = 'Send customer WhatsApp reminders for appointments within the 24h window';

    public function handle(BookingReminderService $service): int
    {
        // Only shops that could actually deliver: bookings module on, reminders
        // enabled, and a configured WhatsApp account.
        $shops = Shop::query()
            ->where('booking_reminders_enabled', true)
            ->whereHas('waAccount')
            ->with('waAccount')
            ->get()
            ->filter(fn (Shop $s) => $s->is_master || $s->hasModule('bookings'));

        $sent = 0;

        foreach ($shops as $shop) {
            $candidates = Booking::where('shop_id', $shop->id)
                ->whereRaw("LOWER(status) = 'booked'")
                ->whereNull('reminder_customer_sent_at')
                ->whereBetween('date', [now()->toDateString(), now()->addDay()->toDateString()])
                ->whereNotNull('customer_whatsapp')
                ->get();

            foreach ($candidates as $booking) {
                if (! $service->isWithinReminderWindow($booking)) {
                    continue;
                }

                try {
                    $service->send($shop, $booking);
                    $booking->update(['reminder_customer_sent_at' => now()]);
                    $sent++;
                } catch (\Throwable $e) {
                    // Leave the flag unset so the next hourly run retries.
                    Log::warning('booking reminder send failed', [
                        'shop_id' => $shop->id,
                        'booking_id' => $booking->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Sent {$sent} customer reminder(s).");

        return self::SUCCESS;
    }
}
