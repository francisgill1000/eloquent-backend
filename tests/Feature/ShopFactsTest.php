<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Wa\PersonaResolver;
use App\Support\Wa\ShopFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The assistant's prompt is grounded in the shop's real services and hours. */
class ShopFactsTest extends TestCase
{
    use RefreshDatabase;

    private function salonWithData(): Shop
    {
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9, 'location' => 'Marina, Dubai']);
        $shop->catalogs()->create(['title' => 'Haircut', 'price' => 50]);
        $shop->catalogs()->create(['title' => 'Hair Color', 'price' => 150.5]);

        return $shop;
    }

    public function test_facts_list_real_services_with_exact_prices(): void
    {
        $facts = ShopFacts::for($this->salonWithData());

        $this->assertStringContainsString('- Haircut — AED 50.00', $facts);
        $this->assertStringContainsString('- Hair Color — AED 150.50', $facts);
        $this->assertStringContainsString('Location: Marina, Dubai.', $facts);
        $this->assertStringContainsString('in Dubai.', $facts);
        $this->assertStringContainsString('never invent', $facts);
    }

    public function test_facts_show_weekly_hours_and_closed_days(): void
    {
        // The Shop factory's created() hook seeds Mon–Sat 09:00; Sunday stays closed.
        $facts = ShopFacts::for($this->salonWithData());

        $this->assertStringContainsString('Sunday: closed', $facts);
        $this->assertStringContainsString('Monday: 09:00–', $facts);
        $this->assertStringContainsString('Saturday: 09:00–', $facts);
    }

    public function test_empty_catalog_gets_safe_fallback(): void
    {
        $shop = Shop::factory()->create();

        $this->assertStringContainsString('service list is not published yet', ShopFacts::for($shop));
    }

    public function test_system_prompt_combines_persona_and_facts(): void
    {
        $shop = $this->salonWithData();
        $shop->update(['persona' => 'You are Bella, the salon receptionist.']);

        $prompt = (new PersonaResolver())->systemPrompt($shop->fresh());

        $this->assertStringStartsWith('You are Bella, the salon receptionist.', $prompt);
        $this->assertStringContainsString('- Haircut — AED 50.00', $prompt);
    }

    public function test_reply_pipeline_sends_grounded_prompt_to_claude(): void
    {
        config(['services.anthropic.key' => 'sk-test', 'services.webpush.public_key' => null]);
        \Illuminate\Support\Facades\Http::fake([
            'api.anthropic.com/v1/messages' => \Illuminate\Support\Facades\Http::response([
                'content' => [['type' => 'text', 'text' => 'A haircut is AED 50 😊']],
            ]),
        ]);

        $shop = $this->salonWithData();
        $contact = \App\Models\WaContact::create(['channel' => 'app', 'shop_id' => $shop->id, 'device_id' => 'dev-facts']);
        $inbound = $contact->recordMessage('in', 'what services do you have?');

        dispatch_sync(new \App\Jobs\ProcessWaReply($inbound->id));

        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => str_contains($request->url(), 'anthropic')
            && str_contains($request['system'][0]['text'], '- Haircut — AED 50.00')
            && str_contains($request['system'][0]['text'], 'Opening hours:'));
    }
}
