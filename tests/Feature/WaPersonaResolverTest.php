<?php

namespace Tests\Feature;

use App\Models\BotPrompt;
use App\Models\Shop;
use App\Models\ShopCustomer;
use App\Models\WaAccount;
use App\Services\Wa\PersonaResolver;
use App\Support\Wa\Prompts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaPersonaResolverTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $phoneNumberId, ?Shop $shop = null): WaAccount
    {
        return WaAccount::create([
            'shop_id' => $shop?->id,
            'phone_number' => '+971500000003',
            'phone_number_id' => $phoneNumberId,
            'waba_id' => 'waba_p',
        ]);
    }

    public function test_tenant_uses_custom_persona_when_set(): void
    {
        $shop = Shop::factory()->create(['persona' => 'You are Bella, the salon receptionist.']);
        $result = (new PersonaResolver())->resolve($this->account('pn_tenant', $shop), '971555000111');

        $this->assertSame('You are Bella, the salon receptionist.', $result['prompt']);
        $this->assertFalse($result['offerTools']);
    }

    public function test_tenant_falls_back_to_category_prompt(): void
    {
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9, 'persona' => null]);
        $result = (new PersonaResolver())->resolve($this->account('pn_tenant2', $shop), '971555000111');

        $this->assertStringContainsString('Glow Salon, a salon business', $result['prompt']);
        $this->assertFalse($result['offerTools']);
    }

    public function test_sales_lead_gets_sales_prompt_with_tools(): void
    {
        config(['services.whatsapp.sales_phone_number_id' => 'pn_sales']);
        $result = (new PersonaResolver())->resolve($this->account('pn_sales'), '971555000111');

        $this->assertSame(Prompts::REZZY_SALES, $result['prompt']);
        $this->assertTrue($result['offerTools']);
    }

    public function test_sales_override_wins_for_everyone_and_disables_tools(): void
    {
        config(['services.whatsapp.sales_phone_number_id' => 'pn_sales']);
        // Mirror the master panel: only one prompt is active at a time.
        BotPrompt::where('is_active', true)->update(['is_active' => false]);
        BotPrompt::create(['name' => 'Salon Test', 'body' => 'You are a test salon bot.', 'is_active' => true, 'is_default' => false]);

        $result = (new PersonaResolver())->resolve($this->account('pn_sales'), '971555000111');

        $this->assertSame('You are a test salon bot.', $result['prompt']);
        $this->assertFalse($result['offerTools']);
    }

    public function test_default_bot_prompt_is_not_an_override(): void
    {
        config(['services.whatsapp.sales_phone_number_id' => 'pn_sales']);
        // The migration seeds the default "Sales Bot" prompt as the active one.
        $this->assertTrue(BotPrompt::where('is_active', true)->where('is_default', true)->exists());

        $result = (new PersonaResolver())->resolve($this->account('pn_sales'), '971555000111');

        $this->assertSame(Prompts::REZZY_SALES, $result['prompt']);
        $this->assertTrue($result['offerTools']);
    }

    public function test_sales_known_customer_gets_provider_prompt(): void
    {
        config(['services.whatsapp.sales_phone_number_id' => 'pn_sales']);
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9]);
        ShopCustomer::create([
            'shop_id' => $shop->id,
            'name' => 'Aisha',
            'whatsapp' => '+971555000111',
            'whatsapp_normalized' => '971555000111',
        ]);

        $result = (new PersonaResolver())->resolve($this->account('pn_sales', $shop), '971555000111');

        $this->assertStringContainsString('Glow Salon, a salon business', $result['prompt']);
        $this->assertFalse($result['offerTools']);
    }
}
