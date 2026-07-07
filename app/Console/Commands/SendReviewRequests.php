<?php

namespace App\Console\Commands;

use App\Models\BookingReview;
use App\Services\Booking\BookingReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sends pending post-visit review requests over WhatsApp. Runs hourly. Tenant-
 * scoped and idempotent (review_request_sent_at flag); a send failure is caught
 * so the next run retries.
 */
class SendReviewRequests extends Command
{
    protected $signature = 'reviews:send-requests';

    protected $description = 'Send pending post-visit WhatsApp review requests to customers';

    public function handle(BookingReviewService $service): int
    {
        $pending = BookingReview::query()
            ->whereNull('review_request_sent_at')
            ->whereNull('rating')
            ->whereHas('shop', fn ($q) => $q->where('booking_reviews_enabled', true)->whereHas('waAccount'))
            ->with(['shop.waAccount', 'booking'])
            ->get();

        $sent = 0;

        foreach ($pending as $review) {
            $shop = $review->shop;
            if (! $shop || ! $review->booking || empty($review->booking->customer_whatsapp)) {
                continue;
            }
            if (! ($shop->is_master || $shop->hasModule('bookings'))) {
                continue;
            }

            try {
                $service->send($shop, $review);
                $review->update(['review_request_sent_at' => now()]);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('review request send failed', [
                    'shop_id' => $shop->id,
                    'review_id' => $review->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$sent} review request(s).");

        return self::SUCCESS;
    }
}
