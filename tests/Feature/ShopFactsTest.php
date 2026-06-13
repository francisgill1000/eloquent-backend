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

    public function test_recognised_customer_appears_in_prompt_with_upcoming_bookings(): void
    {
        $shop = $this->salonWithData();
        $customer = \App\Models\ShopCustomer::create([
            'shop_id' => $shop->id, 'name' => 'Aisha Khan',
            'whatsapp' => '+971550001111', 'whatsapp_normalized' => '971550001111',
        ]);
        \App\Models\Booking::create([
            'shop_id' => $shop->id, 'shop_customer_id' => $customer->id, 'status' => 'booked',
            'date' => now('Asia/Dubai')->addDays(2)->toDateString(), 'start_time' => '10:00', 'end_time' => '10:30',
            'services' => [['title' => 'Haircut']],
        ]);
        $contact = \App\Models\WaContact::create([
            'channel' => 'app', 'shop_id' => $shop->id, 'device_id' => 'dev-known', 'wa_number' => '971550001111',
        ]);

        $facts = ShopFacts::for($shop, $contact);

        $this->assertStringContainsString('KNOWN CUSTOMER', $facts);
        $this->assertStringContainsString('Aisha Khan', $facts);
        $this->assertStringContainsString('Haircut', $facts);
        $this->assertStringContainsString('at 10:00', $facts);
        // An anonymous thread gets no customer block.
        $anon = \App\Models\WaContact::create(['channel' => 'app', 'shop_id' => $shop->id, 'device_id' => 'dev-anon']);
        $this->assertStringNotContainsString('KNOWN CUSTOMER', ShopFacts::for($shop, $anon));
    }

    public function test_bare_greeting_welcomes_known_customer_back_by_name(): void
    {
        config(['services.anthropic.key' => 'sk-test', 'services.webpush.public_key' => null]);
        \Illuminate\Support\Facades\Http::fake();
        $shop = $this->salonWithData();
        \App\Models\ShopCustomer::create([
            'shop_id' => $shop->id, 'name' => 'Aisha Khan',
            'whatsapp' => '+971550001111', 'whatsapp_normalized' => '971550001111',
        ]);
        $contact = \App\Models\WaContact::create([
            'channel' => 'app', 'shop_id' => $shop->id, 'device_id' => 'dev-greet2', 'wa_number' => '971550001111',
        ]);
        $inbound = $contact->recordMessage('in', 'hi');

        dispatch_sync(new \App\Jobs\ProcessWaReply($inbound->id));

        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertStringContainsString('Hi Aisha! 😊 Welcome back to Glow Salon', $out->body);
        \Illuminate\Support\Facades\Http::assertNothingSent(); // canned — no Claude cost
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
