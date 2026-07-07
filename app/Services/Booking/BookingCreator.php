<?php
namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\ShopCustomer;
use App\Services\StaffAssigner;
use Carbon\Carbon;

/**
 * The core "create a booking" logic (working hours + staff assignment + customer
 * registration + end-slot), shared by the HTTP controller and the owner
 * assistant. Promo/campaign resolution and notifications stay with the caller.
 */
class BookingCreator
{
    public function create(Shop $shop, array $data): Booking
    {
        $date = Carbon::parse($data['date'])->format('Y-m-d');
        $startTime = $data['start_time'];

        $workingHour = $shop->getWorkingHourOrFail($date);

        $staff = (new StaffAssigner())->pickStaffForSlot(
            shopId: $shop->id, date: $date, startTime: $startTime,
        );

        $shopCustomer = ShopCustomer::findOrCreateForShop(
            $shop->id, $data['customer_whatsapp'] ?? null, $data['customer_name'] ?? null,
        );

        // A booking is "booked" when a free staff member is available, else queued.
        $confirmed = (bool) $staff;

        // Duration honours per-service duration + buffer when configured, else
        // falls back to the shop's global slot length (legacy behaviour).
        $minutes = app(BookingDurationService::class)->computeMinutes(
            $shop, $data['services'] ?? [], (int) $workingHour->slot_duration,
        );

        return Booking::create([
            'status'                => $confirmed ? 'booked' : 'queued',
            'shop_id'               => $shop->id,
            'shop_customer_id'      => $shopCustomer?->id,
            'staff_id'              => $confirmed ? $staff?->id : null,
            'date'                  => $date,
            'start_time'            => $startTime,
            'end_time'              => $shop->getEndSlot($startTime, $minutes),
            'device_id'             => $data['device_id'] ?? null,
            'charges'               => $data['charges'] ?? 0,
            'services'              => $data['services'] ?? [],
            'customer_name'         => $data['customer_name'] ?? null,
            'customer_whatsapp'     => $data['customer_whatsapp'] ?? null,
            'promo_code_id'         => $data['promo_code_id'] ?? null,
            'marketing_campaign_id' => $data['marketing_campaign_id'] ?? null,
            'discount_amount'       => $data['discount_amount'] ?? 0,
            'recurring_series_id'   => $data['recurring_series_id'] ?? null,
        ]);
    }
}
