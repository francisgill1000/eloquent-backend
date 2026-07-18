<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use App\Models\Staff;
use App\Services\StaffAssigner;
use App\Services\StaffAvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private StaffAvailabilityService $svc;

    private function actingOwner(Shop $shop): string
    {
        setPermissionsTeamId($shop->id);
        $owner = Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($owner);

        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();

        return $new->plainTextToken;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(StaffAvailabilityService::class);
    }

    private function staff(Shop $shop): Staff
    {
        return Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
    }

    private function mondayShift(Staff $staff, Shop $shop): void
    {
        $staff->schedules()->create([
            'shop_id' => $shop->id, 'day_of_week' => 1, // Monday
            'start_time' => '09:00:00', 'end_time' => '12:00:00',
        ]);
    }

    public function test_no_schedule_or_time_off_is_available(): void
    {
        $shop = Shop::factory()->create();
        $staff = $this->staff($shop);
        $this->assertTrue($this->svc->isAvailable($staff, now()->toDateString(), '10:00'));
    }

    public function test_schedule_constrains_to_shift_and_weekday(): void
    {
        $shop = Shop::factory()->create();
        $staff = $this->staff($shop);
        $this->mondayShift($staff, $shop);

        $monday = now()->next(Carbon::MONDAY)->toDateString();
        $tuesday = now()->next(Carbon::TUESDAY)->toDateString();

        $this->assertTrue($this->svc->isAvailable($staff, $monday, '10:00'));   // inside shift
        $this->assertFalse($this->svc->isAvailable($staff, $monday, '13:00'));  // after shift
        $this->assertFalse($this->svc->isAvailable($staff, $tuesday, '10:00')); // no shift that day
    }

    public function test_full_day_time_off_blocks_the_date(): void
    {
        $shop = Shop::factory()->create();
        $staff = $this->staff($shop);
        $this->mondayShift($staff, $shop);
        $monday = now()->next(Carbon::MONDAY)->toDateString();
        $staff->timeOff()->create(['shop_id' => $shop->id, 'date' => $monday]); // full day

        $this->assertFalse($this->svc->isAvailable($staff, $monday, '10:00'));
    }

    public function test_partial_time_off_blocks_only_its_window(): void
    {
        $shop = Shop::factory()->create();
        $staff = $this->staff($shop);
        $monday = now()->next(Carbon::MONDAY)->toDateString();
        $staff->timeOff()->create([
            'shop_id' => $shop->id, 'date' => $monday,
            'start_time' => '12:00:00', 'end_time' => '13:00:00', 'reason' => 'Lunch',
        ]);

        $this->assertFalse($this->svc->isAvailable($staff, $monday, '12:30'));
        $this->assertTrue($this->svc->isAvailable($staff, $monday, '11:00'));
    }

    public function test_assigner_skips_unavailable_and_picks_available_staff(): void
    {
        $shop = Shop::factory()->create();
        $monday = now()->next(Carbon::MONDAY)->toDateString();

        $off = $this->staff($shop);
        $off->timeOff()->create(['shop_id' => $shop->id, 'date' => $monday]); // full day off

        $available = Staff::create(['shop_id' => $shop->id, 'name' => 'Sara', 'is_active' => true]);

        $picked = (new StaffAssigner())->pickStaffForSlot($shop->id, $monday, '10:00');
        $this->assertSame($available->id, $picked?->id);
    }

    public function test_schedule_put_replaces_week_and_time_off_crud_is_tenant_scoped(): void
    {
        $shop = Shop::factory()->create();
        $staff = $this->staff($shop);
        $token = $this->actingOwner($shop);
        $auth = ['Authorization' => "Bearer $token"];

        // PUT replace week
        $this->withHeaders($auth)->putJson("/api/shops/{$shop->id}/staff/{$staff->id}/schedule", [
            'schedule' => [
                ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
                ['day_of_week' => 2, 'start_time' => '10:00', 'end_time' => '14:00'],
            ],
        ])->assertOk()->assertJsonCount(2, 'data');

        // Replace again with a single day — old rows gone.
        $this->withHeaders($auth)->putJson("/api/shops/{$shop->id}/staff/{$staff->id}/schedule", [
            'schedule' => [['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '12:00']],
        ])->assertOk()->assertJsonCount(1, 'data');

        // Add + delete time-off
        $off = $this->withHeaders($auth)->postJson("/api/shops/{$shop->id}/staff/{$staff->id}/time-off", [
            'date' => now()->next(Carbon::MONDAY)->toDateString(), 'reason' => 'Leave',
        ])->assertCreated()->json('data.id');

        $this->withHeaders($auth)->deleteJson("/api/shops/{$shop->id}/staff/{$staff->id}/time-off/{$off}")->assertOk();
        $this->assertDatabaseMissing('staff_time_off', ['id' => $off]);

        // Tenant scoping: another shop cannot touch this staff.
        $other = Shop::factory()->create();
        $this->getJson("/api/shops/{$other->id}/staff/{$staff->id}/schedule")->assertNotFound();
    }
}
