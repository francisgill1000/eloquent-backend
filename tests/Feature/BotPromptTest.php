<?php

namespace Tests\Feature;

use App\Models\BotPrompt;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotPromptTest extends TestCase
{
    use RefreshDatabase;

    private function authed(Shop $shop): array
    {
        $token = $shop->createToken('test')->plainTextToken;
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_master_lists_prompts_including_the_seeded_default(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);

        $list = $this->getJson('/api/master/bot-prompts', $this->authed($master))
            ->assertOk()
            ->json('data');

        $default = collect($list)->firstWhere('is_default', true);
        $this->assertNotNull($default, 'a default prompt should be seeded');
        $this->assertTrue($default['is_active'], 'the default is active out of the box');
    }

    public function test_master_creates_a_non_default_inactive_prompt(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);

        $created = $this->postJson('/api/master/bot-prompts', [
            'name' => 'Salon',
            'body' => 'You are a friendly salon booking assistant.',
        ], $this->authed($master))->assertCreated()->json('data');

        $this->assertSame('Salon', $created['name']);
        $this->assertFalse($created['is_default']);
        $this->assertFalse($created['is_active']);
    }

    public function test_activating_a_prompt_deactivates_all_others(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $salon = BotPrompt::create(['name' => 'Salon', 'body' => 'Salon assistant.']);

        $this->postJson("/api/master/bot-prompts/{$salon->id}/activate", [], $this->authed($master))
            ->assertOk();

        $this->assertTrue($salon->fresh()->is_active);
        $this->assertSame(1, BotPrompt::where('is_active', true)->count());
        $this->assertFalse(BotPrompt::where('is_default', true)->first()->is_active);
    }

    public function test_the_default_prompt_cannot_be_deleted(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $default = BotPrompt::where('is_default', true)->first();

        $this->deleteJson("/api/master/bot-prompts/{$default->id}", [], $this->authed($master))
            ->assertStatus(422);

        $this->assertDatabaseHas('bot_prompts', ['id' => $default->id]);
    }

    public function test_a_custom_prompt_can_be_deleted(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $salon = BotPrompt::create(['name' => 'Salon', 'body' => 'Salon assistant.']);

        $this->deleteJson("/api/master/bot-prompts/{$salon->id}", [], $this->authed($master))
            ->assertOk();

        $this->assertDatabaseMissing('bot_prompts', ['id' => $salon->id]);
    }

    public function test_deleting_the_active_prompt_reactivates_the_default(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $salon = BotPrompt::create(['name' => 'Salon', 'body' => 'Salon assistant.', 'is_active' => true]);
        BotPrompt::where('is_default', true)->update(['is_active' => false]);

        $this->deleteJson("/api/master/bot-prompts/{$salon->id}", [], $this->authed($master))
            ->assertOk();

        $this->assertTrue(BotPrompt::where('is_default', true)->first()->is_active);
    }

    public function test_a_regular_shop_cannot_touch_prompts(): void
    {
        $shop = Shop::factory()->create(['is_master' => false]);
        $this->getJson('/api/master/bot-prompts', $this->authed($shop))->assertForbidden();
    }

    public function test_a_guest_cannot_touch_prompts(): void
    {
        $this->getJson('/api/master/bot-prompts')->assertUnauthorized();
    }

    public function test_relay_returns_override_when_a_custom_prompt_is_active(): void
    {
        config(['services.whatsapp.relay_secret' => 'relay-secret']);
        $salon = BotPrompt::create(['name' => 'Salon', 'body' => 'You are a salon assistant.', 'is_active' => true]);
        BotPrompt::where('is_default', true)->update(['is_active' => false]);

        $this->withHeader('X-Relay-Secret', 'relay-secret')
            ->getJson('/api/wa/sales-prompt')
            ->assertOk()
            ->assertJson([
                'override' => true,
                'name' => 'Salon',
                'body' => 'You are a salon assistant.',
            ]);
    }

    public function test_relay_returns_no_override_when_the_default_is_active(): void
    {
        config(['services.whatsapp.relay_secret' => 'relay-secret']);

        $this->withHeader('X-Relay-Secret', 'relay-secret')
            ->getJson('/api/wa/sales-prompt')
            ->assertOk()
            ->assertJson(['override' => false]);
    }

    public function test_relay_rejects_a_missing_or_wrong_secret(): void
    {
        config(['services.whatsapp.relay_secret' => 'relay-secret']);

        $this->getJson('/api/wa/sales-prompt')->assertForbidden();
        $this->withHeader('X-Relay-Secret', 'nope')
            ->getJson('/api/wa/sales-prompt')->assertForbidden();
    }
}
