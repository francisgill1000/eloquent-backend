<?php
namespace Tests\Feature\Assistant;

use App\Models\AssistantPendingAction;
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
}
