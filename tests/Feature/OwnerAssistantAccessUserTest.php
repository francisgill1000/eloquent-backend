<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use App\Services\Assistant\Modules\AccessTools;
use App\Services\Assistant\Support\ToolCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The owner voice-assistant's staff tools now create/edit login accounts with
 * email + password (PIN retired). The security-critical invariant: the spoken
 * password value must never appear in a preview, a confirmation, or a result.
 */
class OwnerAssistantAccessUserTest extends TestCase
{
    use RefreshDatabase;

    /** Invoke an AccessTools tool as the owner (null acting user = owner-equivalent). */
    private function tool(Shop $shop, string $tool, array $input, bool $confirmed): array
    {
        $call = new ToolCall($shop, null, $tool, $input, $confirmed);
        return (new AccessTools())->run($call);
    }

    public function test_create_user_stores_a_hashed_password_and_email(): void
    {
        $shop = Shop::factory()->create();

        $res = $this->tool($shop, 'create_user', [
            'name' => 'Sara',
            'email' => 'sara@example.com',
            'password' => 'voicePass123',
        ], confirmed: true);

        $this->assertTrue($res['done'] ?? false);
        $this->assertSame('Sara', $res['name']);
        $this->assertArrayNotHasKey('password', $res);

        $staff = ShopUser::where('shop_id', $shop->id)->where('email', 'sara@example.com')->first();
        $this->assertNotNull($staff);
        $this->assertTrue((bool) $staff->is_active);
        $this->assertTrue(Hash::check('voicePass123', $staff->password));
        $this->assertNotSame('voicePass123', $staff->password);
    }

    public function test_create_user_preview_never_contains_the_password(): void
    {
        $shop = Shop::factory()->create();

        $res = $this->tool($shop, 'create_user', [
            'name' => 'Sara',
            'email' => 'sara@example.com',
            'password' => 'leaky-Password-99',
        ], confirmed: false);

        $this->assertTrue($res['preview'] ?? false);
        $this->assertFalse($res['saved']);
        $this->assertStringNotContainsString('leaky-Password-99', json_encode($res));
        // Nothing written on the preview turn.
        $this->assertDatabaseMissing('shop_users', ['email' => 'sara@example.com']);
    }

    public function test_create_user_rejects_an_email_already_used_by_a_shop(): void
    {
        Shop::factory()->create(['email' => 'taken@example.com']);
        $shop = Shop::factory()->create();

        $res = $this->tool($shop, 'create_user', [
            'name' => 'Dup',
            'email' => 'taken@example.com',
            'password' => 'voicePass123',
        ], confirmed: true);

        $this->assertArrayHasKey('error', $res);
        $this->assertDatabaseMissing('shop_users', ['name' => 'Dup']);
    }

    public function test_create_user_rejects_an_email_already_used_by_another_staff(): void
    {
        $shop = Shop::factory()->create();
        ShopUser::factory()->create(['shop_id' => $shop->id, 'email' => 'staff@example.com']);

        $res = $this->tool($shop, 'create_user', [
            'name' => 'Dup',
            'email' => 'staff@example.com',
            'password' => 'voicePass123',
        ], confirmed: true);

        $this->assertArrayHasKey('error', $res);
        $this->assertDatabaseMissing('shop_users', ['name' => 'Dup']);
    }

    public function test_create_user_rejects_a_weak_password(): void
    {
        $shop = Shop::factory()->create();

        $res = $this->tool($shop, 'create_user', [
            'name' => 'Sara',
            'email' => 'sara@example.com',
            'password' => 'short',
        ], confirmed: true);

        $this->assertArrayHasKey('error', $res);
        $this->assertDatabaseMissing('shop_users', ['email' => 'sara@example.com']);
    }

    public function test_create_user_requires_an_email(): void
    {
        $shop = Shop::factory()->create();

        $res = $this->tool($shop, 'create_user', [
            'name' => 'Sara',
            'password' => 'voicePass123',
        ], confirmed: true);

        $this->assertArrayHasKey('error', $res);
        $this->assertDatabaseMissing('shop_users', ['name' => 'Sara']);
    }

    public function test_update_user_sets_a_new_hashed_password_and_email(): void
    {
        $shop = Shop::factory()->create();
        $staff = ShopUser::factory()->create([
            'shop_id' => $shop->id, 'name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'old-password',
        ]);

        $res = $this->tool($shop, 'update_user', [
            'name' => 'Bob',
            'email' => 'bob-new@example.com',
            'password' => 'newVoicePass123',
        ], confirmed: true);

        $this->assertTrue($res['done'] ?? false);

        $fresh = $staff->fresh();
        $this->assertSame('bob-new@example.com', $fresh->email);
        $this->assertTrue(Hash::check('newVoicePass123', $fresh->password));
    }

    public function test_update_user_with_no_password_keeps_the_existing_one(): void
    {
        $shop = Shop::factory()->create();
        $staff = ShopUser::factory()->create([
            'shop_id' => $shop->id, 'name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'original-pass',
        ]);

        $res = $this->tool($shop, 'update_user', [
            'name' => 'Bob',
            'new_name' => 'Bobby',
        ], confirmed: true);

        $this->assertTrue($res['done'] ?? false);
        $fresh = $staff->fresh();
        $this->assertSame('Bobby', $fresh->name);
        $this->assertTrue(Hash::check('original-pass', $fresh->password));
    }

    public function test_update_user_preview_never_contains_the_password(): void
    {
        $shop = Shop::factory()->create();
        ShopUser::factory()->create(['shop_id' => $shop->id, 'name' => 'Bob', 'email' => 'bob@example.com']);

        $res = $this->tool($shop, 'update_user', [
            'name' => 'Bob',
            'password' => 'leaky-Update-Pw-1',
        ], confirmed: false);

        $this->assertTrue($res['preview'] ?? false);
        $this->assertStringNotContainsString('leaky-Update-Pw-1', json_encode($res));
    }
}
