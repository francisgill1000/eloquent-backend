<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Staff;
use App\Services\Wa\PersonaResolver;
use App\Support\Wa\PromptGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The "Generate from profile" prompt and the verbatim persona behaviour. */
class PromptGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function salon(): Shop
    {
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9, 'location' => 'Marina, Dubai']);
        $shop->catalogs()->create(['title' => 'Haircut', 'price' => 50]);
        $shop->catalogs()->create(['title' => 'Hair Color', 'price' => 150.5]);
        Staff::create(['shop_id' => $shop->id, 'name' => 'Maya', 'is_active' => true]);

        return $shop;
    }

    public function test_generated_prompt_includes_profile_facts(): void
    {
        $prompt = PromptGenerator::generate($this->salon());

        $this->assertStringContainsString('Glow Salon, a salon business', $prompt);
        $this->assertStringContainsString('Location: Marina, Dubai.', $prompt);
        $this->assertStringContainsString('- Haircut — AED 50.00', $prompt);
        $this->assertStringContainsString('- Hair Color — AED 150.50', $prompt);
        $this->assertStringContainsString('OPENING HOURS', $prompt);
        $this->assertStringContainsString('Sunday: closed', $prompt);
        $this->assertStringContainsString('Team: Maya.', $prompt);
        $this->assertStringContainsString('BOOKING:', $prompt);
    }

    public function test_empty_catalog_gets_safe_fallback(): void
    {
        $prompt = PromptGenerator::generate(Shop::factory()->create());

        $this->assertStringContainsString('not published yet', $prompt);
    }

    public function test_system_prompt_uses_custom_persona_verbatim(): void
    {
        $shop = $this->salon();
        $shop->update(['persona' => 'You are Bella. Be brief.']);

        // Exactly the saved text — nothing injected.
        $this->assertSame('You are Bella. Be brief.', (new PersonaResolver())->systemPrompt($shop->fresh()));
    }

    public function test_system_prompt_falls_back_to_generated_when_empty(): void
    {
        $prompt = (new PersonaResolver())->systemPrompt($this->salon());

        $this->assertStringContainsString('- Haircut — AED 50.00', $prompt);
        $this->assertStringContainsString('Glow Salon', $prompt);
    }
}
