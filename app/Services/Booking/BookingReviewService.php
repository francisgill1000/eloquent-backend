<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingReview;
use App\Models\Shop;
use App\Services\WhatsAppCloud;

/**
 * Post-visit review requests: create a pending review on completion, then send
 * the customer a tenant-safe WhatsApp rating link. Mirrors the reminders path
 * (each shop uses its own WaAccount + template; sends are idempotent + isolated).
 */
class BookingReviewService
{
    public const DEFAULT_TEMPLATE =
        'Hi {name}, thanks for visiting {shop}! How was your experience? '
        . 'Tap to leave a quick rating: {link}';

    public function __construct(private WhatsAppCloud $wa)
    {
    }

    /**
     * Create a pending review row for a just-completed booking. No-op when
     * reviews are disabled, the customer has no WhatsApp, or one already exists.
     */
    public function createRequestFor(Booking $booking): ?BookingReview
    {
        $shop = $booking->shop;
        if (! $shop || ! $shop->booking_reviews_enabled) {
            return null;
        }
        if (empty($booking->customer_whatsapp)) {
            return null;
        }
        if (BookingReview::where('booking_id', $booking->id)->exists()) {
            return null;
        }

        return BookingReview::create([
            'shop_id'          => $shop->id,
            'booking_id'       => $booking->id,
            'shop_customer_id' => $booking->shop_customer_id,
        ]);
    }

    public function link(BookingReview $review): string
    {
        return rtrim((string) config('app.customer_url'), '/') . '/review/' . $review->token;
    }

    public function render(Shop $shop, Booking $booking, BookingReview $review): string
    {
        $template = trim((string) $shop->review_request_template) ?: self::DEFAULT_TEMPLATE;

        return strtr($template, [
            '{name}' => $booking->customer_name ?: 'there',
            '{shop}' => $shop->name,
            '{link}' => $this->link($review),
        ]);
    }

    /** Send the review request via the shop's own WhatsApp account. */
    public function send(Shop $shop, BookingReview $review): void
    {
        $account = $shop->waAccount;
        if (! $account) {
            throw new \RuntimeException("Shop {$shop->id} has no WhatsApp account");
        }

        $booking = $review->booking;
        $this->wa->sendText($account, $booking->customer_whatsapp, $this->render($shop, $booking, $review));
    }
}
