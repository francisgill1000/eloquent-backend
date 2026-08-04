<?php
namespace Tests\Feature\Assistant;

use App\Models\AssistantPendingAction;
use App\Models\Conversation;
use App\Models\Shop;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantConfirmEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $code): Shop
    {
        $shop = Shop::create(['name' => 'S', 'shop_code' => $code, 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        return $shop;
    }

    private function pending(Shop $shop, array $attrs = []): AssistantPendingAction
    {
        return AssistantPendingAction::create(array_merge([
            'shop_id' => $shop->id, 'tool' => 'create_staff', 'input' => ['name' => 'Jhon'],
            'summary' => 'Add staff member "Jhon"', 'changes' => ['staff' => 'new: Jhon'],
            'destructive' => false, 'expires_at' => now()->addMinutes(30),
        ], $attrs));
    }

    public function test_confirming_writes_from_the_stored_input(): void
    {
        $shop = $this->shop('7420');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])
            ->assertCreated()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('reply_text', '✅ Add staff member "Jhon"');

        $this->assertSame(['Jhon'], Staff::where('shop_id', $shop->id)->pluck('name')->all());
        $this->assertNotNull($row->fresh()->resolved_at);
    }

    public function test_the_client_cannot_smuggle_different_values(): void
    {
        $shop = $this->shop('7421');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop);

        // Extra keys in the request body must be ignored: only `id` is read.
        $this->postJson('/api/shop/assistant/confirm', [
            'id' => $row->id, 'tool' => 'delete_user', 'input' => ['name' => 'Mallory'],
        ])->assertCreated();

        $this->assertSame(['Jhon'], Staff::where('shop_id', $shop->id)->pluck('name')->all());
    }

    public function test_a_destructive_row_writes_because_the_user_confirmed_it(): void
    {
        $shop = $this->shop('7422');
        Sanctum::actingAs($shop, ['*']);
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $row = $this->pending($shop, [
            'tool' => 'delete_staff', 'input' => ['name' => 'Ali'],
            'summary' => 'Delete staff member "Ali"', 'destructive' => true,
        ]);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertCreated();

        $this->assertSame(0, Staff::where('shop_id', $shop->id)->count());
    }

    public function test_another_shops_row_is_not_found(): void
    {
        $mine = $this->shop('7423');
        $theirs = $this->shop('7424');
        Sanctum::actingAs($mine, ['*']);
        $row = $this->pending($theirs);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertNotFound();
        $this->assertSame(0, Staff::where('shop_id', $theirs->id)->count());
    }

    public function test_a_resolved_row_conflicts_and_writes_nothing(): void
    {
        $shop = $this->shop('7425');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop, ['resolved_at' => now()]);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertStatus(409);
        $this->assertSame(0, Staff::where('shop_id', $shop->id)->count());
    }

    public function test_an_expired_row_conflicts_and_writes_nothing(): void
    {
        $shop = $this->shop('7426');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop, ['expires_at' => now()->subMinute()]);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertStatus(409);
        $this->assertSame(0, Staff::where('shop_id', $shop->id)->count());
    }

    public function test_the_confirmation_line_is_appended_to_the_thread(): void
    {
        $shop = $this->shop('7427');
        Sanctum::actingAs($shop, ['*']);
        $conversation = Conversation::create(['shop_id' => $shop->id, 'title' => 'setup', 'source' => 'owner']);
        $row = $this->pending($shop, ['conversation_id' => $conversation->id]);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertCreated();

        $this->assertSame(
            ['✅ Add staff member "Jhon"'],
            $conversation->messages()->where('role', 'assistant')->pluck('content')->all(),
        );
    }
}
