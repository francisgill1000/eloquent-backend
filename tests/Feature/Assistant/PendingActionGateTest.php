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
}
