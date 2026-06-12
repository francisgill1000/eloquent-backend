<?php

namespace App\Services\Wa;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\ShopCustomer;
use App\Models\WaContact;
use App\Services\Notify;
use App\Services\StaffAssigner;
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
                'description' => 'Create the booking for the customer. Call ONLY after the customer has explicitly confirmed the full summary: service, date, time, their full name and their phone number.',
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
        ];
    }

    /** Execute one tool call; always returns a JSON string for the model. */
    public function execute(Shop $shop, WaContact $contact, string $name, array $input): string
    {
        try {
            $result = match ($name) {
                'check_availability' => $this->checkAvailability($shop, $input),
                'create_booking' => $this->createBooking($shop, $contact, $input),
                default => ['error' => "Unknown tool {$name}"],
            };
        } catch (\Throwable $e) {
            Log::warning("WA booking tool {$name} failed: " . $e->getMessage());
            $result = ['error' => 'Something went wrong — tell the customer the team will confirm shortly.'];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
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

        return [
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
        ];
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
