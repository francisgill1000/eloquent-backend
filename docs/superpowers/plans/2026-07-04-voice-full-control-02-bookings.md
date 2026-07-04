# Voice Full Control — Plan 02: BookingTools Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.
>
> **PREREQUISITE:** Plan 01 (Foundation) must be implemented AND its suite green (`php artisan test --filter=Assistant`). This plan refactors the tested `BookingController`; do not start until the foundation is verified.

**Goal:** Give the owner assistant full, safe control of bookings by voice — find, create, reschedule, change status, cancel, and delete — behaving **identically** to the app's screens (staff assignment, queue sweep, invoice lifecycle), gated by the confirm-everything gate and per-user RBAC.

**Architecture:** First extract the booking side-effect logic out of `BookingController` into two services (`BookingCreator`, `BookingStatusService`) that both the controller AND the assistant call — so voice never drifts from the UI. Then add `App\Services\Assistant\Modules\BookingTools` (a `MutatingTool`) whose write handlers delegate to those services, and register it.

**Tech Stack:** Laravel (PHP 8.2+), PHPUnit/Pest.

## Global Constraints

- Every tool scoped to `$call->shop`; a booking is only ever resolved/changed within the acting shop (no cross-shop access).
- RBAC via `Rbac::userCan`; permissions: `bookings.view` (find), `bookings.create`, `bookings.update` (reschedule / status / cancel), `bookings.delete`.
- Confirm-gate invariant: no write unless `confirmed === true`.
- Status side-effects that MUST be preserved from `BookingController::update`: when a **booked** slot moves to **cancelled**/**completed** and had a staff, free `staff_id` and `sweep()` the queue; on **completed** issue an invoice (`firstOrCreate`); on **cancelled** cancel the invoice.
- Create side-effects that MUST be preserved from `BookingController::bookSlot`: working-hours lookup (`getWorkingHourOrFail`), staff auto-assign (`StaffAssigner::pickStaffForSlot`), `ShopCustomer::findOrCreateForShop`, end-slot computation (`getEndSlot`), status `booked` if staff else `queued`, auto `booking_reference`.
- The class is `App\Services\Assistant\Modules\BookingTools` — NOT the existing `App\Services\Wa\BookingTools`. Keep them distinct.
- Tests are run by the user (this dev box has no PHP). Each task states the exact command + expected result.

---

## File Structure

- Create `app/Services/Booking/BookingCreator.php` — shared create-a-booking core (hours + staff + customer + end-slot + create).
- Create `app/Services/Booking/BookingStatusService.php` — shared status transition (vacate + sweep + invoice lifecycle).
- Modify `app/Http/Controllers/BookingController.php` — `bookSlot()` delegates the core create to `BookingCreator`; `update()` delegates the transition to `BookingStatusService`. Behaviour unchanged.
- Create `app/Services/Assistant/Modules/BookingTools.php` — the gated owner-assistant module.
- Modify `app/Services/Assistant/AssistantToolRegistry.php` — add `BookingTools` to the constructor + `modules()`.
- Modify `app/Services/Assistant/OwnerAssistantTools.php` — remove the now-superseded `cancel_booking` + `update_booking_status` tool defs and `execute()` cases (BookingTools owns them; `get_bookings` stays as a read tool for now).
- Tests: `tests/Feature/BookingCreatorTest.php`, `tests/Feature/BookingStatusServiceTest.php`, `tests/Feature/Assistant/BookingToolsModuleTest.php`.

---

### Task 1: Extract `BookingStatusService`

**Files:**
- Create: `app/Services/Booking/BookingStatusService.php`
- Modify: `app/Http/Controllers/BookingController.php:233-292` (`update`)
- Test: `tests/Feature/BookingStatusServiceTest.php`; guard: `tests/Feature/BookingInvoiceTest.php`, `tests/Feature/BookingAssignmentTest.php`

**Interfaces:**
- Produces `BookingStatusService::apply(Booking $booking, string $newStatus): Booking` — lower-cases `$newStatus`; if a **booked** slot with a staff moves to cancelled/completed, nulls `staff_id` then `StaffAssigner::sweep(shopId, date, rawStartTime)`; on completed `BookingInvoice::firstOrCreate`; on cancelled cancels the invoice. Returns the fresh booking.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingInvoice;
use App\Models\Shop;
use App\Models\Staff;
use App\Services\Booking\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create(['name' => 'S', 'shop_code' => '8001', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
    }

    public function test_cancelling_a_booked_slot_frees_staff_and_cancels_invoice(): void
    {
        $shop = $this->shop();
        $staff = Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $booking = Booking::create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id, 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'booked', 'charges' => 40,
            'discount_amount' => 0, 'services' => [], 'customer_name' => 'C',
        ]);
        BookingInvoice::create(['booking_id' => $booking->id, 'subtotal' => 40, 'total' => 40, 'status' => 'issued', 'issued_at' => now()]);

        app(BookingStatusService::class)->apply($booking, 'cancelled');

        $this->assertSame('cancelled', strtolower($booking->fresh()->getRawOriginal('status')));
        $this->assertNull($booking->fresh()->staff_id);
        $this->assertSame('cancelled', $booking->fresh()->invoice->status);
    }

    public function test_completing_a_booked_slot_issues_an_invoice(): void
    {
        $shop = $this->shop();
        $staff = Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $booking = Booking::create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id, 'date' => now()->toDateString(),
            'start_time' => '11:00', 'end_time' => '11:30', 'status' => 'booked', 'charges' => 60,
            'discount_amount' => 0, 'services' => [], 'customer_name' => 'C',
        ]);

        app(BookingStatusService::class)->apply($booking, 'completed');

        $this->assertSame('completed', strtolower($booking->fresh()->getRawOriginal('status')));
        $this->assertNotNull($booking->fresh()->invoice);
        $this->assertSame('issued', $booking->fresh()->invoice->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BookingStatusServiceTest`
Expected: FAIL — class `App\Services\Booking\BookingStatusService` not found.

- [ ] **Step 3: Create the service (logic lifted verbatim from `BookingController::update`)**

```php
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
```

- [ ] **Step 4: Refactor `BookingController::update` to delegate**

Replace the body from `$previousStatus = ...` through the invoice block (lines ~245-286) with a single call, keeping the 404 + validation:

```php
    public function update(Request $request, $id)
    {
        $booking = Booking::find($id);
        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:booked,completed,cancelled,Booked,Completed,Cancelled'
        ]);

        $fresh = app(\App\Services\Booking\BookingStatusService::class)->apply($booking, $validated['status']);

        return response()->json([
            'message' => 'Booking updated successfully',
            'data' => $fresh,
        ]);
    }
```

- [ ] **Step 5: Run the new + guard suites**

Run: `php artisan test --filter=BookingStatusServiceTest`
Then: `php artisan test --filter=BookingInvoiceTest` and `php artisan test --filter=BookingAssignmentTest`
Expected: PASS for all (behaviour preserved).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Booking/BookingStatusService.php app/Http/Controllers/BookingController.php tests/Feature/BookingStatusServiceTest.php
git commit -m "refactor(bookings): extract BookingStatusService shared by controller + assistant"
```

---

### Task 2: Extract `BookingCreator`

**Files:**
- Create: `app/Services/Booking/BookingCreator.php`
- Modify: `app/Http/Controllers/BookingController.php:54-163` (`bookSlot`) — delegate the core create.
- Test: `tests/Feature/BookingCreatorTest.php`; guard: `tests/Feature/BookingAssignmentTest.php`

**Interfaces:**
- Produces `BookingCreator::create(Shop $shop, array $data): Booking`.
  - `$data` keys: `customer_name` (string), `customer_whatsapp` (?string), `date` (string), `start_time` (string `HH:MM`), `services` (array), `charges` (float), `discount_amount` (float, default 0), `promo_code_id` (?int), `marketing_campaign_id` (?int), `device_id` (?string).
  - Does: `getWorkingHourOrFail($date)`, `StaffAssigner::pickStaffForSlot`, `ShopCustomer::findOrCreateForShop`, `getEndSlot`, `Booking::create` with status `booked` if staff else `queued`. Returns the `Booking`.
  - Does NOT resolve promo codes, attribute campaigns, or notify — the controller keeps that around the call; the assistant passes `charges` = summed service prices and null promo/campaign.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Staff;
use App\Models\ShopCustomer;
use App\Services\Booking\BookingCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingCreatorTest extends TestCase
{
    use RefreshDatabase;

    private function shopWithHours(): Shop
    {
        $shop = Shop::create(['name' => 'S', 'shop_code' => '8100', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        \DB::table('shop_working_hours')->insert([
            'shop_id' => $shop->id, 'day_of_week' => (int) now()->dayOfWeek,
            'start_time' => '09:00:00', 'end_time' => '18:00:00', 'slot_duration' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $shop;
    }

    public function test_create_assigns_free_staff_and_registers_customer(): void
    {
        $shop = $this->shopWithHours();
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);

        $booking = app(BookingCreator::class)->create($shop, [
            'customer_name' => 'Sara', 'customer_whatsapp' => '971500000000',
            'date' => now()->toDateString(), 'start_time' => '10:00',
            'services' => [['title' => 'Cut', 'price' => '50.00']], 'charges' => 50.0,
        ]);

        $this->assertSame('booked', strtolower($booking->getRawOriginal('status')));
        $this->assertNotNull($booking->staff_id);
        $this->assertSame('Sara', $booking->customer_name);
        $this->assertNotNull($booking->booking_reference);
        $this->assertNotNull(ShopCustomer::where('shop_id', $shop->id)->first());
    }

    public function test_create_without_free_staff_queues(): void
    {
        $shop = $this->shopWithHours(); // no staff created
        $booking = app(BookingCreator::class)->create($shop, [
            'customer_name' => 'Sara', 'customer_whatsapp' => null,
            'date' => now()->toDateString(), 'start_time' => '10:00',
            'services' => [], 'charges' => 0.0,
        ]);
        $this->assertSame('queued', strtolower($booking->getRawOriginal('status')));
        $this->assertNull($booking->staff_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BookingCreatorTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the service**

```php
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

        return Booking::create([
            'status'                => $staff ? 'booked' : 'queued',
            'shop_id'               => $shop->id,
            'shop_customer_id'      => $shopCustomer?->id,
            'staff_id'              => $staff?->id,
            'date'                  => $date,
            'start_time'            => $startTime,
            'end_time'              => $shop->getEndSlot($startTime, $workingHour->slot_duration),
            'device_id'             => $data['device_id'] ?? null,
            'charges'               => $data['charges'] ?? 0,
            'services'              => $data['services'] ?? [],
            'customer_name'         => $data['customer_name'] ?? null,
            'customer_whatsapp'     => $data['customer_whatsapp'] ?? null,
            'promo_code_id'         => $data['promo_code_id'] ?? null,
            'marketing_campaign_id' => $data['marketing_campaign_id'] ?? null,
            'discount_amount'       => $data['discount_amount'] ?? 0,
        ]);
    }
}
```

- [ ] **Step 4: Refactor `BookingController::bookSlot` to delegate the core create**

Keep the promo/campaign resolution exactly as-is; replace the `Booking::create([...])` block (lines ~113-132) with:

```php
                $booking = app(\App\Services\Booking\BookingCreator::class)->create($shop, [
                    'customer_name'         => $request->customer_name,
                    'customer_whatsapp'     => $request->customer_whatsapp,
                    'date'                  => $date,
                    'start_time'            => $startTime,
                    'services'              => $request->services ?? [],
                    'charges'               => $finalCharges,
                    'discount_amount'       => $discountAmount,
                    'promo_code_id'         => $promoCodeId,
                    'marketing_campaign_id' => $campaignId,
                    'device_id'             => $request->header('X-Device-Id'),
                ]);
```

(The `$date`, `$startTime`, and `$workingHour` locals earlier in `bookSlot` remain; `BookingCreator` re-derives them safely. Leave the promo increment, campaign attribution, notify, and response exactly as they are.)

- [ ] **Step 5: Run the new + guard suites**

Run: `php artisan test --filter=BookingCreatorTest`
Then: `php artisan test --filter=BookingAssignmentTest`
Expected: PASS for both.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Booking/BookingCreator.php app/Http/Controllers/BookingController.php tests/Feature/BookingCreatorTest.php
git commit -m "refactor(bookings): extract BookingCreator shared by controller + assistant"
```

---

### Task 3: The `BookingTools` assistant module

**Files:**
- Create: `app/Services/Assistant/Modules/BookingTools.php`
- Test: `tests/Feature/Assistant/BookingToolsModuleTest.php`

**Interfaces:**
- Consumes: `MutatingTool` (Plan 01), `BookingCreator`, `BookingStatusService`, `ToolCall`.
- Produces module `App\Services\Assistant\Modules\BookingTools extends MutatingTool` owning: `find_booking` (`bookings.view`), `create_booking` (`bookings.create`), `reschedule_booking` / `update_booking_status` / `cancel_booking` (`bookings.update`), `delete_booking` (`bookings.delete`).
- Resolution: bookings by `booking_reference` (case-insensitive, scoped to `$call->shop`); services by title against `catalogs`.

- [ ] **Step 1: Write the failing test** (confirm-gate + RBAC + resolution)

```php
<?php
namespace Tests\Feature\Assistant;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\Staff;
use App\Services\Assistant\Modules\BookingTools;
use App\Services\Assistant\Support\ToolCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingToolsModuleTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create(['name' => 'S', 'shop_code' => '8200', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
    }

    private function booking(Shop $shop, string $ref = 'BK00001'): Booking
    {
        $staff = Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $b = Booking::create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id, 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'booked', 'charges' => 40,
            'discount_amount' => 0, 'services' => [], 'customer_name' => 'C',
        ]);
        $b->update(['booking_reference' => $ref]); // pin a known reference
        return $b->fresh();
    }

    private function call(Shop $shop, string $tool, array $input, bool $confirmed): ToolCall
    {
        return new ToolCall($shop, null, $tool, $input, $confirmed); // null user = owner-equivalent
    }

    public function test_cancel_unconfirmed_previews_and_does_not_write(): void
    {
        $shop = $this->shop();
        $this->booking($shop);
        $out = app(BookingTools::class)->run($this->call($shop, 'cancel_booking', ['reference' => 'BK00001'], false));

        $this->assertTrue($out['preview']);
        $this->assertSame('booked', strtolower(Booking::where('booking_reference', 'BK00001')->first()->getRawOriginal('status')));
    }

    public function test_cancel_confirmed_frees_staff_via_status_service(): void
    {
        $shop = $this->shop();
        $this->booking($shop);
        $out = app(BookingTools::class)->run($this->call($shop, 'cancel_booking', ['reference' => 'BK00001'], true));

        $this->assertTrue($out['done']);
        $fresh = Booking::where('booking_reference', 'BK00001')->first();
        $this->assertSame('cancelled', strtolower($fresh->getRawOriginal('status')));
        $this->assertNull($fresh->staff_id); // side-effect via BookingStatusService
    }

    public function test_unknown_reference_returns_not_found(): void
    {
        $shop = $this->shop();
        $out = app(BookingTools::class)->run($this->call($shop, 'cancel_booking', ['reference' => 'NOPE'], true));
        $this->assertSame('not_found', $out['error']);
    }

    public function test_booking_from_another_shop_is_not_found(): void
    {
        $shop = $this->shop();
        $other = Shop::create(['name' => 'O', 'shop_code' => '8299', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        $this->booking($other, 'BK09999');
        $out = app(BookingTools::class)->run($this->call($shop, 'cancel_booking', ['reference' => 'BK09999'], true));
        $this->assertSame('not_found', $out['error']); // scoped to acting shop
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BookingToolsModuleTest`
Expected: FAIL — class `App\Services\Assistant\Modules\BookingTools` not found.

- [ ] **Step 3: Create the module**

```php
<?php
namespace App\Services\Assistant\Modules;

use App\Models\Booking;
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
    ) {}

    protected function permissions(): array
    {
        return [
            'find_booking'          => 'bookings.view',
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
            ['name' => 'create_booking', 'description' => 'Create a booking. Requires customer_name, date (YYYY-MM-DD) and start_time (HH:MM); services is a list of service titles. Call with confirmed:true only after the owner confirms.', 'input_schema' => ['type' => 'object', 'properties' => [
                'customer_name' => ['type' => 'string'],
                'customer_whatsapp' => ['type' => 'string'],
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'start_time' => ['type' => 'string', 'description' => 'HH:MM 24h'],
                'services' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Service titles'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['customer_name', 'date', 'start_time']]],
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BookingToolsModuleTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Assistant/Modules/BookingTools.php tests/Feature/Assistant/BookingToolsModuleTest.php
git commit -m "feat(assistant): gated BookingTools module (create/reschedule/status/cancel/delete/find)"
```

---

### Task 4: Register the module + retire the legacy booking writes

**Files:**
- Modify: `app/Services/Assistant/AssistantToolRegistry.php` — add `BookingTools`.
- Modify: `app/Services/Assistant/OwnerAssistantTools.php` — remove `cancel_booking` + `update_booking_status` from `defs()` and `execute()` (BookingTools owns them now; `get_bookings` stays).
- Test: `tests/Feature/AssistantToolRegistryTest.php` (extend), `tests/Feature/OwnerAssistantConfirmGateTest.php` (update — see note), `tests/Feature/OwnerAssistantMutationTest.php` (update).

**Interfaces:**
- Consumes: `BookingTools` (Task 3).
- Produces: registry `modules()` returns `[$this->legacy, $this->bookings]`; the kill-switch now hides `BookingTools` (a `MutatingTool`) when mutations are disabled.

- [ ] **Step 1: Add BookingTools to the registry**

```php
    public function __construct(
        protected OwnerAssistantTools $legacy,
        protected \App\Services\Assistant\Modules\BookingTools $bookings,
    ) {}

    protected function modules(): array
    {
        return [$this->legacy, $this->bookings];
    }
```

- [ ] **Step 2: Extend the registry test**

```php
    public function test_booking_tools_are_registered(): void
    {
        $names = array_column(app(AssistantToolRegistry::class)->defs(), 'name');
        $this->assertContains('cancel_booking', $names);
        $this->assertContains('create_booking', $names);
    }

    public function test_kill_switch_hides_mutating_booking_tools(): void
    {
        config(['assistant.mutations_enabled' => false]);
        $names = array_column(app(AssistantToolRegistry::class)->defs(), 'name');
        $this->assertNotContains('cancel_booking', $names);
        $this->assertContains('get_revenue', $names); // reads remain
    }
```

- [ ] **Step 3: Remove the superseded tools from `OwnerAssistantTools`**

In `defs()` delete the `cancel_booking` and `update_booking_status` array entries. In `execute()` delete their `match` arms and the `cancelBooking()` / `updateStatus()` methods. Keep `get_revenue`, `get_top_services`, `get_staff_performance`, `get_busy_times`, `get_bookings`, `update_hours`, `update_service_price` (those move in Plans 06/03).

- [ ] **Step 4: Update the legacy confirm-gate + mutation tests**

`OwnerAssistantConfirmGateTest` and `OwnerAssistantMutationTest` drive `cancel_booking` through the HTTP endpoint. Under the new gate, the applying tool call must include `confirmed:true`. Update each fake tool-call input to add `'confirmed' => true` on the post-confirmation turn (the turn that expects the DB to change). Example edit in `OwnerAssistantConfirmGateTest`:

```php
                ->push(['content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'cancel_booking', 'input' => ['reference' => 'BK00001', 'confirmed' => true]]]]) // turn 2 tool call — now carries confirmed:true
```

Leave turn 1 (no tool_use) unchanged — it still asserts the booking stays `booked`. The unconfirmed-preview path is additionally covered by `BookingToolsModuleTest`.

- [ ] **Step 5: Run the full assistant + bookings suites**

Run: `php artisan test --filter=Assistant`
Then: `php artisan test --filter=Booking`
Expected: PASS across registry, booking module, confirm-gate, mutation, controller, creator, status-service, assignment, invoice.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Assistant/AssistantToolRegistry.php app/Services/Assistant/OwnerAssistantTools.php tests/Feature/AssistantToolRegistryTest.php tests/Feature/OwnerAssistantConfirmGateTest.php tests/Feature/OwnerAssistantMutationTest.php
git commit -m "feat(assistant): register BookingTools and retire legacy booking write tools"
```

---

## Self-Review

**Spec coverage (bookings slice):** find/create/reschedule/status/cancel/delete → Task 3 tools ✓; behaviour matches UI via extracted `BookingCreator`/`BookingStatusService` → Tasks 1–2 ✓; confirm-gate (preview vs write) → BookingToolsModuleTest ✓; RBAC map → `permissions()` ✓; shop-scoping → `resolveBooking` where + `test_booking_from_another_shop_is_not_found` ✓; kill-switch → Task 4 test ✓.

**Placeholder scan:** No TBD/"handle edge cases"/"similar to" — every step has real code + commands. ✓

**Type consistency:** `BookingStatusService::apply(Booking,string):Booking`, `BookingCreator::create(Shop,array):Booking` used identically in controller edits and module `write` closures. `gate(resolve,describe,write)` matches Plan 01's signature; `resolve` returns a `Booking` OR `notFound()` array (the `?? $this->notFound()` idiom), and `gate` passes arrays through / hands `Booking` objects to `describe`/`write` — consistent within this module. ✓

**Known limitation (documented, not a gap):** `reschedule_booking` updates date/time + end-slot but does not re-run staff assignment for the new slot (keeps the existing staff). Full re-assignment on reschedule is deferred; called out here so it isn't mistaken for coverage.

## Next: Plan 03 — ServiceTools (services.manage): list/create/update/delete, migrating `update_service_price` out of the legacy module.
