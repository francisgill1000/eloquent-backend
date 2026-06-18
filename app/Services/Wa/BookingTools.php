<?php

namespace App\Services\Wa;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\ShopCustomer;
use App\Models\WaContact;
use App\Services\Notify;
use App\Services\StaffAssigner;
use App\Support\Wa\CustomerContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Claude tools that let the chat assistant act on real data: read free slots
 * and create actual bookings. A booking always registers the customer as a
 * ShopCustomer (found by phone or created), so returning customers are
 * recognised and never asked to register again.
 */
class BookingTools
{
    public static function defs(): array
    {
        return [
            [
                'name' => 'check_availability',
                'description' => 'Get the real free booking slots for one date. Always call this before suggesting or agreeing to any time.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'The date to check, format YYYY-MM-DD'],
                    ],
                    'required' => ['date'],
                ],
            ],
            [
                'name' => 'create_booking',
                'description' => 'Create a real booking and register the customer. Call ONLY after BOTH happened, in order: (1) you sent one message repeating the full summary (service, date, time, name, phone) and asked "shall I confirm?", and (2) the customer replied yes to that exact message. The customer giving their name and phone is NOT confirmation — you must still send the summary and wait for the yes. Never tell a customer a booking is confirmed, or quote a booking reference, unless this tool returned booked:true in the current reply. When the result includes a payment_url, the booking is reserved but NOT yet paid: give the customer the booking reference, then share the payment_url on its own line and tell them to tap it to pay and confirm. Do NOT say "paid" or "payment received" — payment is confirmed separately once they complete it. When there is no payment_url, just confirm the booking normally.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'time' => ['type' => 'string', 'description' => 'HH:MM (24h), must be one of the free slots'],
                        'customer_name' => ['type' => 'string', 'description' => "The customer's full name"],
                        'customer_phone' => ['type' => 'string', 'description' => "The customer's phone/WhatsApp number, with country code when given"],
                        'service_title' => ['type' => 'string', 'description' => 'The service title exactly as it appears in the services list'],
                    ],
                    'required' => ['date', 'time', 'customer_name', 'customer_phone'],
                ],
            ],
            [
                'name' => 'my_bookings',
                'description' => "List this customer's upcoming bookings at the shop. Call it before cancelling or rescheduling anything.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_phone' => ['type' => 'string', 'description' => 'Only needed when the customer is not yet recognised in this chat'],
                    ],
                ],
            ],
            [
                'name' => 'cancel_booking',
                'description' => 'Cancel one upcoming booking. Call ONLY after you asked "Are you sure you want to cancel <reference> …?" and the customer replied yes.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reference' => ['type' => 'string', 'description' => 'The booking reference, e.g. BK00042'],
                        'customer_phone' => ['type' => 'string', 'description' => 'Only needed when the customer is not yet recognised in this chat'],
                    ],
                    'required' => ['reference'],
                ],
            ],
            [
                'name' => 'reschedule_booking',
                'description' => 'Move one upcoming booking to a new date/time. Check availability for the new date first. Call ONLY after the customer confirmed the new slot with a yes.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reference' => ['type' => 'string', 'description' => 'The booking reference, e.g. BK00042'],
                        'new_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'new_time' => ['type' => 'string', 'description' => 'HH:MM (24h), must be one of the free slots'],
                        'customer_phone' => ['type' => 'string', 'description' => 'Only needed when the customer is not yet recognised in this chat'],
                    ],
                    'required' => ['reference', 'new_date', 'new_time'],
                ],
            ],
        ];
    }

    /** Execute one tool call; always returns a JSON string for the model. */
    public function execute(Shop $shop, WaContact $contact, string $name, array $input): string
    {
        try {
            $result = match ($name) {
                'check_availability' => $this->checkAvailability($shop, $input),
                'create_booking' => $this->createBooking($shop, $contact, $input),
                'my_bookings' => $this->myBookings($shop, $contact, $input),
                'cancel_booking' => $this->cancelBooking($shop, $contact, $input),
                'reschedule_booking' => $this->rescheduleBooking($shop, $contact, $input),
                default => ['error' => "Unknown tool {$name}"],
            };
        } catch (\Throwable $e) {
            Log::warning("WA booking tool {$name} failed: " . $e->getMessage());
            $result = ['error' => 'Something went wrong — tell the customer the team will confirm shortly.'];
        }

        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        Log::info("WA tool {$name} (shop {$shop->id}, contact {$contact->id})", ['in' => $input, 'out' => $result]);

        return $json;
    }

    private function checkAvailability(Shop $shop, array $input): array
    {
        $date = $this->parseDate($input['date'] ?? '');
        if (!$date) {
            return ['error' => 'Invalid date — use YYYY-MM-DD.'];
        }
        if ($date->lt(Carbon::now('Asia/Dubai')->startOfDay())) {
            return ['error' => 'That date is in the past.'];
        }

        $hours = $shop->working_hours()->where('day_of_week', $date->dayOfWeek)->first();
        if (!$hours) {
            return ['date' => $date->toDateString(), 'day' => $date->format('l'), 'closed' => true];
        }

        $slots = Shop::getSlots(
            $date->toDateString(),
            $hours->start_time ?? '09:00:00',
            $hours->end_time ?? '17:00:00',
            $hours->slot_duration ?? 30,
            $shop->id,
        );

        return [
            'date' => $date->toDateString(),
            'day' => $date->format('l'),
            'open' => substr((string) $hours->start_time, 0, 5),
            'close' => substr((string) $hours->end_time, 0, 5),
            'free_slots' => $slots,
        ];
    }

    private function createBooking(Shop $shop, WaContact $contact, array $input): array
    {
        $date = $this->parseDate($input['date'] ?? '');
        $time = trim((string) ($input['time'] ?? ''));
        $customerName = trim((string) ($input['customer_name'] ?? ''));
        $customerPhone = trim((string) ($input['customer_phone'] ?? ''));

        if (!$date || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return ['error' => 'Invalid date or time — date YYYY-MM-DD, time HH:MM.'];
        }
        if ($customerName === '' || ShopCustomer::normalize($customerPhone) === '') {
            return ['error' => "Missing the customer's name or a valid phone number — ask for them first."];
        }

        // The slot must still be free right now.
        $availability = $this->checkAvailability($shop, ['date' => $date->toDateString()]);
        if (!empty($availability['error']) || !empty($availability['closed'])) {
            return ['error' => 'The shop is closed that day or the date is invalid.', 'availability' => $availability];
        }
        if (!in_array($time, $availability['free_slots'], true)) {
            return ['error' => 'That time is no longer free — offer one of the free slots.', 'free_slots' => $availability['free_slots']];
        }

        $service = null;
        if (!empty($input['service_title'])) {
            $title = mb_strtolower(trim((string) $input['service_title']));
            $service = $shop->catalogs()->get(['id', 'title', 'price'])
                ->first(fn ($s) => mb_strtolower(trim((string) $s->title)) === $title)
                ?? $shop->catalogs()->where('title', 'LIKE', '%' . trim((string) $input['service_title']) . '%')->first();
        }

        $returning = ShopCustomer::where('shop_id', $shop->id)
            ->where('whatsapp_normalized', 'LIKE', '%' . substr(ShopCustomer::normalize($customerPhone), -9))
            ->exists();

        $booking = DB::transaction(function () use ($shop, $contact, $date, $time, $customerName, $customerPhone, $service) {
            // Registered customer, not a guest: found by phone or created now,
            // so next time the system already knows them.
            $customer = ShopCustomer::findOrCreateForShop($shop->id, $customerPhone, $customerName);

            $hours = $shop->working_hours()->where('day_of_week', $date->dayOfWeek)->first();
            $staff = (new StaffAssigner())->pickStaffForSlot(
                shopId: $shop->id,
                date: $date->toDateString(),
                startTime: $time,
            );

            return Booking::create([
                'status' => $staff ? 'booked' : 'queued',
                'shop_id' => $shop->id,
                'shop_customer_id' => $customer?->id,
                'staff_id' => $staff?->id,
                'date' => $date->toDateString(),
                'start_time' => $time,
                'end_time' => $shop->getEndSlot($time, $hours->slot_duration ?? 30),
                'device_id' => $contact->device_id,
                'charges' => $service?->price !== null ? (float) $service->price : 0,
                'services' => $service ? [['id' => $service->id, 'title' => $service->title, 'price' => $service->price]] : [],
                'customer_name' => $customerName,
                'customer_whatsapp' => $customerPhone,
            ]);
        });

        // The chat thread now has a real identity — show it in the owner inbox.
        $contact->update([
            'name' => $contact->name ?: $customerName,
            'wa_number' => $contact->wa_number ?: ShopCustomer::normalize($customerPhone),
        ]);

        try {
            Notify::push($shop->id, 'booking', "New booking from chat: {$booking->booking_reference}", $booking->toArray());
        } catch (\Throwable $e) {
            Log::warning('Booking notify failed: ' . $e->getMessage());
        }

        // Generate a Ziina payment link so the customer can pay to confirm.
        // Null (no service price, under 2 AED, or Ziina error) → just confirm
        // the booking without a link; the booking itself is never lost.
        $paymentUrl = null;
        try {
            $link = app(\App\Services\Ziina::class)->paymentLinkForBooking($booking);
            if (!empty($link['ok'])) {
                $paymentUrl = $link['url'];
            }
        } catch (\Throwable $e) {
            Log::warning('Ziina chat payment link failed: ' . $e->getMessage());
        }

        return array_filter([
            'booked' => true,
            'status' => $booking->status, // Booked, or Queued when no staff is free
            'reference' => $booking->booking_reference,
            'date' => $date->toDateString(),
            'day' => $date->format('l'),
            'time' => $time,
            'service' => $service?->title,
            'price_aed' => $service?->price !== null ? (float) $service->price : null,
            'customer_name' => $customerName,
            'returning_customer' => $returning,
            'payment_url' => $paymentUrl, // omitted when null (array_filter)
        ], fn ($v) => $v !== null);
    }

    private function myBookings(Shop $shop, WaContact $contact, array $input): array
    {
        $customer = $this->resolveCustomer($shop, $contact, $input);
        if (!$customer) {
            return ['error' => 'No registered customer found — ask for the phone number used when booking.'];
        }

        $upcoming = CustomerContext::upcomingBookings($shop, $customer);

        return [
            'customer' => $customer->name,
            'upcoming_bookings' => $upcoming->map(fn ($b) => CustomerContext::describe($b))->values()->all(),
        ];
    }

    private function cancelBooking(Shop $shop, WaContact $contact, array $input): array
    {
        $booking = $this->ownedUpcomingBooking($shop, $contact, $input);
        if (is_array($booking)) {
            return $booking; // error payload
        }

        $hadStaff = strtolower($booking->getRawOriginal('status')) === 'booked' && $booking->staff_id !== null;

        // Mirror the owner-side cancel: vacate the staff so a queued booking
        // on the same slot can be promoted, and cancel any invoice.
        $booking->update(['status' => 'cancelled', 'staff_id' => $hadStaff ? null : $booking->staff_id]);
        if ($hadStaff) {
            (new StaffAssigner())->sweep(
                shopId: $shop->id,
                date: Carbon::parse($booking->date)->format('Y-m-d'),
                startTime: $booking->getRawOriginal('start_time'),
            );
        }
        $booking->load('invoice');
        $booking->invoice?->update(['status' => 'cancelled']);

        try {
            Notify::push($shop->id, 'booking', "Booking cancelled from chat: {$booking->booking_reference}", $booking->fresh()->toArray());
        } catch (\Throwable $e) {
            Log::warning('Cancel notify failed: ' . $e->getMessage());
        }

        return [
            'cancelled' => true,
            'reference' => $booking->booking_reference,
            'date' => Carbon::parse($booking->date)->toDateString(),
            'time' => $booking->slot,
        ];
    }

    private function rescheduleBooking(Shop $shop, WaContact $contact, array $input): array
    {
        $booking = $this->ownedUpcomingBooking($shop, $contact, $input);
        if (is_array($booking)) {
            return $booking; // error payload
        }

        $date = $this->parseDate($input['new_date'] ?? '');
        $time = trim((string) ($input['new_time'] ?? ''));
        if (!$date || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return ['error' => 'Invalid new date or time — date YYYY-MM-DD, time HH:MM.'];
        }

        $availability = $this->checkAvailability($shop, ['date' => $date->toDateString()]);
        if (!empty($availability['error']) || !empty($availability['closed'])) {
            return ['error' => 'The shop is closed on the new date.', 'availability' => $availability];
        }
        if (!in_array($time, $availability['free_slots'], true)) {
            return ['error' => 'The new time is not free — offer one of the free slots.', 'free_slots' => $availability['free_slots']];
        }

        $oldDate = Carbon::parse($booking->date)->format('Y-m-d');
        $oldTime = $booking->getRawOriginal('start_time');
        $freesOldSlot = strtolower($booking->getRawOriginal('status')) === 'booked' && $booking->staff_id !== null;

        $hours = $shop->working_hours()->where('day_of_week', $date->dayOfWeek)->first();
        $staff = (new StaffAssigner())->pickStaffForSlot(
            shopId: $shop->id,
            date: $date->toDateString(),
            startTime: $time,
        );

        $booking->update([
            'date' => $date->toDateString(),
            'start_time' => $time,
            'end_time' => $shop->getEndSlot($time, $hours->slot_duration ?? 30),
            'staff_id' => $staff?->id,
            'status' => $staff ? 'booked' : 'queued',
            'reminder_sent_at' => null,
        ]);

        // The old slot just freed up — promote anyone queued on it.
        if ($freesOldSlot) {
            (new StaffAssigner())->sweep(shopId: $shop->id, date: $oldDate, startTime: $oldTime);
        }

        try {
            Notify::push($shop->id, 'booking', "Booking rescheduled from chat: {$booking->booking_reference}", $booking->fresh()->toArray());
        } catch (\Throwable $e) {
            Log::warning('Reschedule notify failed: ' . $e->getMessage());
        }

        return [
            'rescheduled' => true,
            'reference' => $booking->booking_reference,
            'status' => $booking->fresh()->status,
            'date' => $date->toDateString(),
            'day' => $date->format('l'),
            'time' => $time,
        ];
    }

    /** The thread's registered customer — by chat identity, or by the phone the model collected. */
    private function resolveCustomer(Shop $shop, WaContact $contact, array $input): ?ShopCustomer
    {
        $customer = CustomerContext::customerFor($shop, $contact);
        if ($customer) {
            return $customer;
        }

        $normalized = ShopCustomer::normalize((string) ($input['customer_phone'] ?? ''));
        if ($normalized === '') {
            return null;
        }
        $tail = strlen($normalized) > 9 ? substr($normalized, -9) : $normalized;

        return ShopCustomer::where('shop_id', $shop->id)
            ->where('whatsapp_normalized', 'LIKE', '%' . $tail)
            ->first();
    }

    /** Find the referenced booking and verify it belongs to this customer and is still upcoming. */
    private function ownedUpcomingBooking(Shop $shop, WaContact $contact, array $input): Booking|array
    {
        $reference = strtoupper(trim((string) ($input['reference'] ?? '')));
        $booking = Booking::where('shop_id', $shop->id)->where('booking_reference', $reference)->first();
        if (!$booking) {
            return ['error' => "No booking {$reference} found at this shop."];
        }

        $customer = $this->resolveCustomer($shop, $contact, $input);
        $ownedByCustomer = $customer && (int) $booking->shop_customer_id === (int) $customer->id;
        $ownedByDevice = $contact->device_id && $booking->device_id === $contact->device_id;
        if (!$ownedByCustomer && !$ownedByDevice) {
            return ['error' => 'That booking does not belong to this customer — ask for the phone number used when booking.'];
        }

        if (!in_array(strtolower($booking->getRawOriginal('status')), ['booked', 'queued'], true)) {
            return ['error' => "Booking {$reference} is already {$booking->status} and cannot be changed."];
        }
        if (Carbon::parse($booking->date)->lt(Carbon::now('Asia/Dubai')->startOfDay())) {
            return ['error' => "Booking {$reference} is in the past."];
        }

        return $booking;
    }

    private function parseDate(string $value): ?Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d', trim($value), 'Asia/Dubai')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
