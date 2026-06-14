<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Wa\PersonaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Personas come exclusively from the shop ("shop flow"): the master-set
 * persona when present, else the category default. There is no special
 * sales persona and no onboarding tool anymore.
 */
class WaPersonaResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_custom_persona_when_set(): void
    {
        $shop = Shop::factory()->create(['persona' => 'You are Bella, the salon receptionist.']);

        $this->assertSame(
            'You are Bella, the salon receptionist.',
            (new PersonaResolver())->promptForShop($shop)
        );
    }

    public function test_falls_back_to_generated_prompt(): void
    {
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9, 'persona' => null]);

        $prompt = (new PersonaResolver())->promptForShop($shop);

        $this->assertStringContainsString('Glow Salon, a salon business', $prompt);
        $this->assertStringContainsString('BOOKING:', $prompt);
    }

    public function test_whitespace_persona_counts_as_unset(): void
    {
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9, 'persona' => '   ']);

        $this->assertStringContainsString('Glow Salon', (new PersonaResolver())->promptForShop($shop));
    }

    public function test_null_shop_gets_generic_prompt(): void
    {
        $prompt = (new PersonaResolver())->promptForShop(null);

        $this->assertStringContainsString('assistant', $prompt);
    }
}
