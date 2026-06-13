<?php

namespace App\Support\Wa;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\ShopCustomer;
use App\Models\WaContact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Who is this chat thread? Resolves the registered ShopCustomer behind a
 * contact — by phone (WhatsApp threads, or app threads after their first
 * booking) or by the device that created a past booking — so the assistant
 * can greet returning customers by name and act on their bookings.
 */
class CustomerContext
{
    public static function customerFor(Shop $shop, WaContact $contact): ?ShopCustomer
    {
        $normalized = ShopCustomer::normalize($contact->wa_number);
        if ($normalized !== '') {
            $tail = strlen($normalized) > 9 ? substr($normalized, -9) : $normalized;
            $byPhone = ShopCustomer::where('shop_id', $shop->id)
                ->where('whatsapp_normalized', 'LIKE', '%' . $tail)
                ->first();
            if ($byPhone) {
                return $byPhone;
            }
        }

        if ($contact->device_id) {
            return Booking::where('shop_id', $shop->id)
                ->where('device_id', $contact->device_id)
                ->whereNotNull('shop_customer_id')
                ->latest('id')
                ->first()?->shopCustomer;
        }

        return null;
    }

    /** Booked/queued bookings from today onwards, soonest first. */
    public static function upcomingBookings(Shop $shop, ShopCustomer $customer): Collection
    {
        return Booking::where('shop_id', $shop->id)
            ->where('shop_customer_id', $customer->id)
            ->whereDate('date', '>=', Carbon::now('Asia/Dubai')->toDateString())
            ->whereRaw("lower(status) in ('booked', 'queued')")
            ->orderBy('date')->orderBy('start_time')
            ->get();
    }

    /** One compact line per booking, for prompts and tool results. */
    public static function describe(Booking $booking): string
    {
        $service = collect($booking->services ?? [])->pluck('title')->filter()->implode(', ');

        return $booking->booking_reference
            . ' — ' . ($service !== '' ? $service : 'service TBC')
            . ' on ' . Carbon::parse($booking->date)->format('l j M')
            . ' at ' . $booking->slot
            . ' (' . $booking->status . ')';
    }
}
