<?php
namespace App\Services\Assistant\Modules;

use App\Models\Booking;
use App\Services\Assistant\Support\AssistantActions;
use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ToolCall;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingStatusService;
use Illuminate\Support\Facades\DB;

/**
 * Owner-assistant booking tools. Writes go through BookingCreator /
 * BookingStatusService so voice behaves exactly like the app's screens.
 * NOTE: distinct from App\Services\Wa\BookingTools (the WhatsApp chat tools).
 */
class BookingTools extends MutatingTool
{
    public function __construct(
        protected BookingCreator $creator,
        protected BookingStatusService $status,
        protected AssistantActions $actions,
    ) {}

    protected function permissions(): array
    {
        return [
            'find_booking'          => 'bookings.view',
            'open_booking'          => 'bookings.view',
            'create_booking'        => 'bookings.create',
            'reschedule_booking'    => 'bookings.update',
            'update_booking_status' => 'bookings.update',
            'cancel_booking'        => 'bookings.update',
            'delete_booking'        => 'bookings.delete',
        ];
    }

    protected function handle(ToolCall $call): array
    {
        return match ($call->tool) {
            'find_booking'          => $this->find($call),
            'open_booking'          => $this->open($call),
            'create_booking'        => $this->create($call),
            'reschedule_booking'    => $this->reschedule($call),
            'update_booking_status' => $this->setStatus($call),
            'cancel_booking'        => $this->cancel($call),
            'delete_booking'        => $this->delete($call),
            default                 => ['error' => 'unknown_tool'],
        };
    }

    /** Resolve one booking by reference within the acting shop. */
    private function resolveBooking(ToolCall $call): ?Booking
    {
        $ref = strtoupper(trim((string) $call->get('reference')));
        if ($ref === '') {
            return null;
        }
        return Booking::where('shop_id', $call->shop->id)
            ->whereRaw('UPPER(booking_reference) = ?', [$ref])
            ->first();
    }

    private function find(ToolCall $call): array
    {
        $booking = $this->resolveBooking($call);
        if (! $booking) {
            return $this->notFound('booking');
        }
        return [
            'reference' => $booking->booking_reference,
            'date' => $booking->date,
            'time' => substr((string) $booking->getRawOriginal('start_time'), 0, 5),
            'customer' => $booking->customer_name,
            'status' => strtolower($booking->getRawOriginal('status')),
            'charges' => (float) $booking->charges,
        ];
    }

    /** Resolve a booking and hand the chat UI a directive to open its detail page. */
    private function open(ToolCall $call): array
    {
        $booking = $this->resolveBooking($call);
        if (! $booking) {
            return $this->notFound('booking');
        }
        $this->actions->navigate("/booking/{$booking->id}");
        return ['opening' => true, 'reference' => $booking->booking_reference];
    }

    private function cancel(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolveBooking($call) ?? $this->notFound('booking'),
            describe: fn ($b) => ["Cancel booking {$b->booking_reference} — {$b->customer_name}, {$b->date} at " . substr((string) $b->getRawOriginal('start_time'), 0, 5), ['status' => strtolower($b->getRawOriginal('status')) . ' → cancelled']],
            write: function ($b) {
                $this->status->apply($b, 'cancelled');
                return ['reference' => $b->booking_reference];
            },
        );
    }

    private function setStatus(ToolCall $call): array
    {
        $new = strtolower((string) $call->get('status'));
        if (! in_array($new, ['booked', 'completed', 'cancelled', 'queued'], true)) {
            return ['error' => 'invalid_status'];
        }
        return $this->gate(
            $call,
            resolve: fn () => $this->resolveBooking($call) ?? $this->notFound('booking'),
            describe: fn ($b) => ["Set booking {$b->booking_reference} to {$new}", ['status' => strtolower($b->getRawOriginal('status')) . " → {$new}"]],
            write: function ($b) use ($new) {
                $this->status->apply($b, $new);
                return ['reference' => $b->booking_reference, 'status' => $new];
            },
        );
    }

    private function reschedule(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolveBooking($call) ?? $this->notFound('booking'),
            describe: function ($b) use ($call) {
                $date = $call->get('date', $b->date);
                $time = $call->get('start_time', substr((string) $b->getRawOriginal('start_time'), 0, 5));
                return ["Move booking {$b->booking_reference} to {$date} at {$time}", ['when' => "{$b->date} " . substr((string) $b->getRawOriginal('start_time'), 0, 5) . " → {$date} {$time}"]];
            },
            write: function ($b) use ($call) {
                $start = (string) $call->get('start_time', substr((string) $b->getRawOriginal('start_time'), 0, 5));
                $wh = $call->shop->getWorkingHourOrFail((string) $call->get('date', $b->date));
                $b->update([
                    'date' => $call->get('date', $b->date),
                    'start_time' => $start,
                    'end_time' => $call->shop->getEndSlot($start, $wh->slot_duration),
                ]);
                return ['reference' => $b->booking_reference];
            },
        );
    }

    private function delete(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolveBooking($call) ?? $this->notFound('booking'),
            describe: fn ($b) => ["Permanently delete booking {$b->booking_reference} — {$b->customer_name}", ['booking' => "{$b->booking_reference} removed"]],
            write: function ($b) {
                $ref = $b->booking_reference;
                $b->delete();
                return ['reference' => $ref];
            },
        );
    }

    private function create(ToolCall $call): array
    {
        // Resolve service titles → charges from this shop's catalog.
        $titles = (array) $call->get('services', []);
        $rows = DB::table('catalogs')->where('shop_id', $call->shop->id)
            ->whereIn('title', $titles)->get(['title', 'price']);
        $services = $rows->map(fn ($r) => ['title' => $r->title, 'price' => (string) $r->price])->all();
        $charges = (float) $rows->sum('price');

        return $this->gate(
            $call,
            resolve: fn () => $call->get('customer_name') && $call->get('date') && $call->get('start_time')
                ? ['ok' => true]
                : ['error' => 'not_found', 'what' => 'missing_fields'],
            describe: fn () => [
                "Book {$call->get('customer_name')} on {$call->get('date')} at {$call->get('start_time')} for " . (implode(', ', $titles) ?: 'no service') . " ({$charges} dirhams)",
                ['booking' => 'new'],
            ],
            write: function () use ($call, $services, $charges) {
                $booking = $this->creator->create($call->shop, [
                    'customer_name' => $call->get('customer_name'),
                    'customer_whatsapp' => $call->get('customer_whatsapp'),
                    'date' => $call->get('date'),
                    'start_time' => $call->get('start_time'),
                    'services' => $services,
                    'charges' => $charges,
                ]);
                return ['reference' => $booking->booking_reference, 'status' => strtolower($booking->getRawOriginal('status'))];
            },
        );
    }

    public function toolDefs(): array
    {
        $ref = ['reference' => ['type' => 'string', 'description' => 'Booking reference, e.g. BK00042']];
        return [
            ['name' => 'find_booking', 'description' => 'Look up one booking by its reference.', 'input_schema' => ['type' => 'object', 'properties' => $ref, 'required' => ['reference']]],
            ['name' => 'open_booking', 'description' => 'Open/show a booking\'s detail page for the owner in the app (this redirects them to it). Use whenever the owner asks to open, show, view, see, or be taken/redirected to a booking. Pass its reference — reuse the one already mentioned in the conversation if you have it.', 'input_schema' => ['type' => 'object', 'properties' => $ref, 'required' => ['reference']]],
            ['name' => 'create_booking', 'description' => 'Create a booking. Requires customer_name, customer_whatsapp (the customer\'s contact number — always ask for it, a booking cannot be created without it), date (YYYY-MM-DD) and start_time (HH:MM); services is a list of service titles. Call with confirmed:true only after the owner confirms.', 'input_schema' => ['type' => 'object', 'properties' => [
                'customer_name' => ['type' => 'string'],
                'customer_whatsapp' => ['type' => 'string', 'description' => 'The customer\'s contact/WhatsApp number — required'],
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'start_time' => ['type' => 'string', 'description' => 'HH:MM 24h'],
                'services' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Service titles'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['customer_name', 'customer_whatsapp', 'date', 'start_time']]],
            ['name' => 'reschedule_booking', 'description' => 'Move a booking to a new date and/or time. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => array_merge($ref, [
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'start_time' => ['type' => 'string', 'description' => 'HH:MM 24h'],
                'confirmed' => ['type' => 'boolean'],
            ]), 'required' => ['reference']]],
            ['name' => 'update_booking_status', 'description' => 'Set a booking status (booked/completed/cancelled/queued). Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => array_merge($ref, [
                'status' => ['type' => 'string', 'enum' => ['booked', 'completed', 'cancelled', 'queued']],
                'confirmed' => ['type' => 'boolean'],
            ]), 'required' => ['reference', 'status']]],
            ['name' => 'cancel_booking', 'description' => 'Cancel one booking by reference. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => array_merge($ref, ['confirmed' => ['type' => 'boolean']]), 'required' => ['reference']]],
            ['name' => 'delete_booking', 'description' => 'Permanently delete a booking record by reference. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => array_merge($ref, ['confirmed' => ['type' => 'boolean']]), 'required' => ['reference']]],
        ];
    }
}
