<?php
namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingInvoice;
use App\Services\StaffAssigner;
use Carbon\Carbon;

/**
 * The status-transition side-effects for a booking, shared by the HTTP
 * controller and the owner assistant so voice behaves exactly like the UI:
 * vacate + sweep the slot when a booked booking is cancelled/completed, and
 * run the invoice lifecycle.
 */
class BookingStatusService
{
    public function apply(Booking $booking, string $newStatus): Booking
    {
        $previousStatus = strtolower($booking->getRawOriginal('status'));
        $previousStaffId = $booking->staff_id;
        $newStatus = strtolower($newStatus);

        $vacates = in_array($newStatus, ['cancelled', 'completed'], true)
            && $previousStatus === 'booked'
            && $previousStaffId !== null;

        $updateData = ['status' => $newStatus];
        if ($vacates) {
            $updateData['staff_id'] = null;
        }
        $booking->update($updateData);

        if ($vacates) {
            (new StaffAssigner())->sweep(
                shopId: $booking->shop_id,
                date: Carbon::parse($booking->date)->format('Y-m-d'),
                startTime: $booking->getRawOriginal('start_time'),
            );
        }

        if ($newStatus === 'completed' && $previousStatus === 'booked') {
            BookingInvoice::firstOrCreate(
                ['booking_id' => $booking->id],
                ['subtotal' => $booking->charges ?? 0, 'total' => $booking->charges ?? 0, 'status' => 'issued', 'issued_at' => now()],
            );
        }

        if ($newStatus === 'cancelled') {
            $booking->load('invoice');
            $booking->invoice?->update(['status' => 'cancelled']);
        }

        return $booking->fresh(['staff', 'invoice']);
    }
}
