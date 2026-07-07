<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\Staff;
use App\Services\Booking\RecurringBookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecurringBookingTest extends TestCase
{
    use RefreshDatabase;

    /** A shop open every day (so recurrence isn't skipped for closed weekdays). */
    private function openEveryDayShop(): Shop
    {
        $shop = Shop::factory()->create();
        for ($d = 0; $d <= 6; $d++) {
            DB::table('shop_working_hours')->updateOrInsert(
                ['shop_id' => $shop->id, 'day_of_week' => $d],
                ['start_time' => '09:00:00', 'end_time' => '20:00:00', 'slot_duration' => 30,
                 'created_at' => now(), 'updated_at' => now()],
            );
        }
        return $shop;
    }

    private function base(): array
    {
        return [
            'date' => Carbon::parse('2026-08-04')->toDateString(), // a Tuesday
            'start_time' => '17:00',
            'services' => [['title' => 'Cut', 'price' => '50.00']],
            'charges' => 50,
            'customer_name' => 'Regular Riya',
            'customer_whatsapp' => '971555000111',
        ];
    }

    public function test_weekly_series_creates_bookings_on_correct_dates_with_one_series_id(): void
    {
        $shop = $this->openEveryDayShop();
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);

        $result = app(RecurringBookingService::class)->createSeries($shop, $this->base(), 'weekly', 4);

        $this->assertCount(4, $result['created']);
        $dates = collect($result['created'])->map(fn ($b) => Carbon::parse($b->date)->toDateString())->all();
        $this->assertSame(['2026-08-04', '2026-08-11', '2026-08-18', '2026-08-25'], $dates);

        // All share one series id, all at the same time.
        $seriesIds = collect($result['created'])->pluck('recurring_series_id')->unique();
        $this->assertCount(1, $seriesIds);
        $this->assertSame($result['series_id'], $seriesIds->first());
        $this->assertSame(4, Booking::where('recurring_series_id', $result['series_id'])->count());
    }

    public function test_biweekly_spacing_is_fourteen_days(): void
    {
        $shop = $this->openEveryDayShop();
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);

        $result = app(RecurringBookingService::class)->createSeries($shop, $this->base(), 'biweekly', 3);
        $dates = collect($result['created'])->map(fn ($b) => Carbon::parse($b->date)->toDateString())->all();
        $this->assertSame(['2026-08-04', '2026-08-18', '2026-09-01'], $dates);
    }

    public function test_occurrence_is_queued_when_no_staff_free(): void
    {
        $shop = $this->openEveryDayShop(); // no staff created
        $result = app(RecurringBookingService::class)->createSeries($shop, $this->base(), 'weekly', 2);

        foreach ($result['created'] as $b) {
            $this->assertSame('queued', strtolower($b->getRawOriginal('status')));
            $this->assertNull($b->staff_id);
        }
    }

    public function test_endpoint_validates_and_creates_series(): void
    {
        $shop = $this->openEveryDayShop();
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);

        // Bad frequency rejected.
        $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->postJson("/api/shops/{$shop->id}/book-recurring", array_merge($this->base(), [
                'frequency' => 'yearly', 'occurrences' => 3,
            ]))->assertStatus(422);

        // Valid request creates the series.
        $res = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->postJson("/api/shops/{$shop->id}/book-recurring", array_merge($this->base(), [
                'frequency' => 'weekly', 'occurrences' => 3,
            ]))->assertCreated();

        $this->assertCount(3, $res->json('created'));
        $this->assertNotEmpty($res->json('series_id'));
    }
}
