<?php
namespace Tests\Feature\Assistant;

use App\Models\AssistantPendingAction;
use App\Models\Shop;
use App\Models\Staff;
use App\Services\Assistant\Modules\StaffTools;
use App\Services\Assistant\Support\AssistantActions;
use App\Services\Assistant\Support\ToolCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingActionGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_row_is_live_and_a_resolved_or_expired_one_is_not(): void
    {
        $live = AssistantPendingAction::create([
            'shop_id' => 1, 'tool' => 'create_staff', 'input' => ['name' => 'Jhon'],
            'summary' => 'Add staff member "Jhon"', 'changes' => ['staff' => 'new: Jhon'],
            'destructive' => false, 'expires_at' => now()->addMinutes(30),
        ]);
        $this->assertTrue($live->isLive());
        $this->assertSame(['name' => 'Jhon'], $live->fresh()->input); // json round-trip

        $resolved = AssistantPendingAction::create([
            'shop_id' => 1, 'tool' => 'create_staff', 'input' => [], 'summary' => 'x',
            'changes' => [], 'destructive' => false,
            'expires_at' => now()->addMinutes(30), 'resolved_at' => now(),
        ]);
        $this->assertFalse($resolved->isLive());

        $expired = AssistantPendingAction::create([
            'shop_id' => 1, 'tool' => 'create_staff', 'input' => [], 'summary' => 'x',
            'changes' => [], 'destructive' => false, 'expires_at' => now()->subMinute(),
        ]);
        $this->assertFalse($expired->isLive());
    }

    public function test_open_scope_finds_only_live_rows_for_that_shop_and_tool(): void
    {
        $mine = AssistantPendingAction::create([
            'shop_id' => 1, 'tool' => 'create_staff', 'input' => [], 'summary' => 'x',
            'changes' => [], 'destructive' => false, 'expires_at' => now()->addMinutes(30),
        ]);
        AssistantPendingAction::create([ // other shop
            'shop_id' => 2, 'tool' => 'create_staff', 'input' => [], 'summary' => 'x',
            'changes' => [], 'destructive' => false, 'expires_at' => now()->addMinutes(30),
        ]);
        AssistantPendingAction::create([ // other tool
            'shop_id' => 1, 'tool' => 'create_service', 'input' => [], 'summary' => 'x',
            'changes' => [], 'destructive' => false, 'expires_at' => now()->addMinutes(30),
        ]);

        $found = AssistantPendingAction::open(1, 'create_staff')->get();
        $this->assertSame([$mine->id], $found->pluck('id')->all());
    }

    public function test_tool_call_defaults_user_confirmed_to_false(): void
    {
        $shop = \App\Models\Shop::create(['name' => 'S', 'shop_code' => '7401', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        $call = new \App\Services\Assistant\Support\ToolCall($shop, null, 'create_staff', ['name' => 'Jhon'], true);
        $this->assertFalse($call->userConfirmed);

        $userCall = new \App\Services\Assistant\Support\ToolCall($shop, null, 'create_staff', [], true, true);
        $this->assertTrue($userCall->userConfirmed);
    }

    private function shop(string $code): Shop
    {
        return Shop::create(['name' => 'S', 'shop_code' => $code, 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
    }

    public function test_preview_records_a_pending_row_and_emits_a_confirm_action(): void
    {
        $shop = $this->shop('7410');
        $actions = app(AssistantActions::class);

        $out = app(StaffTools::class)->run(new ToolCall($shop, null, 'create_staff', ['name' => 'Jhon'], false));

        $this->assertTrue($out['preview']);
        $row = AssistantPendingAction::where('shop_id', $shop->id)->firstOrFail();
        $this->assertSame('create_staff', $row->tool);
        $this->assertSame(['name' => 'Jhon'], $row->input);
        $this->assertSame('Add staff member "Jhon"', $row->summary);
        $this->assertFalse($row->destructive);
        $this->assertTrue($row->isLive());

        $this->assertSame([
            'type' => 'confirm', 'id' => $row->id,
            'summary' => 'Add staff member "Jhon"',
            'changes' => ['staff' => 'new: Jhon'],
            'destructive' => false,
        ], $actions->pending());
    }

    public function test_destructive_tool_refuses_a_model_supplied_confirm_and_writes_nothing(): void
    {
        $shop = $this->shop('7411');
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);

        // confirmed:true, but it came from the model — not a user tap.
        $out = app(StaffTools::class)->run(new ToolCall($shop, null, 'delete_staff', ['name' => 'Ali'], true, false));

        $this->assertTrue($out['preview']);
        $this->assertFalse($out['saved']);
        $this->assertSame(1, Staff::where('shop_id', $shop->id)->count()); // still there
        $this->assertTrue(AssistantPendingAction::where('shop_id', $shop->id)->firstOrFail()->destructive);
    }

    /**
     * The per-call instruction must not order the model to do something the
     * gate forbids. Telling a destructive tool's preview to "call again with
     * confirmed=true" only produces an identical preview, so the model loops
     * until the turn budget is gone and the owner gets no card at all.
     */
    public function test_a_destructive_preview_tells_the_model_not_to_call_again(): void
    {
        $shop = $this->shop('7415');
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);

        $out = app(StaffTools::class)->run(new ToolCall($shop, null, 'delete_staff', ['name' => 'Ali'], false));

        $this->assertStringNotContainsString('confirmed=true', $out['next']);
        $this->assertStringContainsString('Do NOT call this tool again', $out['next']);
        $this->assertStringContainsString('in the app', $out['next']);
    }

    public function test_a_non_destructive_preview_keeps_the_self_confirm_instruction(): void
    {
        $shop = $this->shop('7416');

        $out = app(StaffTools::class)->run(new ToolCall($shop, null, 'create_staff', ['name' => 'Jhon'], false));

        $this->assertStringContainsString('confirmed=true', $out['next']);
    }

    public function test_destructive_tool_writes_when_the_user_confirmed(): void
    {
        $shop = $this->shop('7412');
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);

        $out = app(StaffTools::class)->run(new ToolCall($shop, null, 'delete_staff', ['name' => 'Ali'], true, true));

        $this->assertTrue($out['done']);
        $this->assertSame(0, Staff::where('shop_id', $shop->id)->count());
    }

    public function test_non_destructive_tool_still_self_confirms(): void
    {
        $shop = $this->shop('7413');

        $out = app(StaffTools::class)->run(new ToolCall($shop, null, 'create_staff', ['name' => 'Jhon'], true, false));

        $this->assertTrue($out['done']);
        $this->assertSame(1, Staff::where('shop_id', $shop->id)->count());
    }

    public function test_a_self_confirmed_write_resolves_the_open_card(): void
    {
        $shop = $this->shop('7414');

        // Turn 1: preview leaves a live card.
        app(StaffTools::class)->run(new ToolCall($shop, null, 'create_staff', ['name' => 'Jhon'], false));
        $row = AssistantPendingAction::where('shop_id', $shop->id)->firstOrFail();
        $this->assertTrue($row->isLive());

        // Turn 2: the model confirms it itself.
        app(StaffTools::class)->run(new ToolCall($shop, null, 'create_staff', ['name' => 'Jhon'], true, false));

        $this->assertSame(1, Staff::where('shop_id', $shop->id)->count());
        $this->assertFalse($row->fresh()->isLive()); // card can no longer double-write
    }

    // close_day DELETES a weekday's shop_working_hours row — its open/close times
    // and slot length are gone, and bookings for that weekday stop until the owner
    // notices. Same class of loss as delete_category, so it needs the owner's tap.

    private function hoursFor(Shop $shop, int $day): int
    {
        return \DB::table('shop_working_hours')->where('shop_id', $shop->id)->where('day_of_week', $day)->count();
    }

    public function test_close_day_refuses_a_model_supplied_confirm_and_deletes_nothing(): void
    {
        $shop = $this->shop('7430');
        \DB::table('shop_working_hours')->updateOrInsert(
            ['shop_id' => $shop->id, 'day_of_week' => 5],
            ['start_time' => '09:00:00', 'end_time' => '18:00:00', 'slot_duration' => 30, 'created_at' => now(), 'updated_at' => now()],
        );

        // confirmed:true, but from the model — not an owner tap.
        $out = app(\App\Services\Assistant\Modules\HoursTools::class)
            ->run(new ToolCall($shop, null, 'close_day', ['day_of_week' => 5], true, false));

        $this->assertTrue($out['preview']);
        $this->assertFalse($out['saved']);
        $this->assertSame(1, $this->hoursFor($shop, 5)); // Friday's hours survive
        $this->assertTrue(AssistantPendingAction::where('shop_id', $shop->id)->firstOrFail()->destructive);
    }

    public function test_close_day_deletes_when_the_owner_confirmed(): void
    {
        $shop = $this->shop('7431');
        \DB::table('shop_working_hours')->updateOrInsert(
            ['shop_id' => $shop->id, 'day_of_week' => 5],
            ['start_time' => '09:00:00', 'end_time' => '18:00:00', 'slot_duration' => 30, 'created_at' => now(), 'updated_at' => now()],
        );

        $out = app(\App\Services\Assistant\Modules\HoursTools::class)
            ->run(new ToolCall($shop, null, 'close_day', ['day_of_week' => 5], true, true));

        $this->assertTrue($out['done']);
        $this->assertSame(0, $this->hoursFor($shop, 5));
    }
}
