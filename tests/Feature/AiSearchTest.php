<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI service finder: one Claude call classifies the customer's message into a
 * find / list / off-topic intent. "find" returns matching shops in the same
 * shape the customer ShopCard consumes; "list" returns the available service
 * categories (chips); off-topic returns a refusal.
 */
class AiSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.anthropic.key' => 'sk-test',
            'services.anthropic.model' => 'claude-haiku-4-5',
        ]);
    }

    /** Fake the Anthropic Messages API to return the given JSON classification. */
    private function fakeClaude(string $json): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => $json]],
            ]),
        ]);
    }

    public function test_find_intent_returns_only_matching_category_shops(): void
    {
        $this->fakeClaude('{"intent": "find", "category_id": 1, "reply": "Here are some barbers near you 👇"}');

        $barber = Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 1]);
        Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 2]); // plumber — excluded

        $res = $this->withHeader('X-Device-Id', 'dev-ai')
            ->postJson('/api/ai/search', ['message' => 'find a barber'])
            ->assertOk()
            ->json();

        $this->assertSame(1, $res['category_id']);
        $this->assertStringContainsString('barber', strtolower($res['reply']));
        $this->assertCount(1, $res['shops']);
        $this->assertSame($barber->id, $res['shops'][0]['id']);
        $this->assertSame([], $res['categories']);
    }

    public function test_list_intent_returns_available_categories_with_counts(): void
    {
        $this->fakeClaude('{"intent": "list", "category_id": null, "reply": "Here are the services you can search 👇"}');

        Shop::factory()->count(3)->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 1]); // Barber
        Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 9]);            // Salon
        // category 2 (Plumbing) intentionally has no shops — must be absent.

        $res = $this->withHeader('X-Device-Id', 'dev-ai')
            ->postJson('/api/ai/search', ['message' => 'what services are available?'])
            ->assertOk()
            ->json();

        $this->assertNull($res['category_id']);
        $this->assertSame([], $res['shops']);

        $byId = collect($res['categories'])->keyBy('id');
        $this->assertSame(3, $byId[1]['count']);
        $this->assertSame('Barber', $byId[1]['name']);
        $this->assertSame(1, $byId[9]['count']);
        $this->assertFalse($byId->has(2), 'categories with no shops must be omitted');
    }

    public function test_off_topic_query_returns_no_shops(): void
    {
        $this->fakeClaude('{"intent": "off_topic", "category_id": null, "reply": "I can only help you find local services."}');
        Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 1]);

        $res = $this->withHeader('X-Device-Id', 'dev-ai')
            ->postJson('/api/ai/search', ['message' => "what's the weather?"])
            ->assertOk()
            ->json();

        $this->assertNull($res['category_id']);
        $this->assertSame([], $res['shops']);
        $this->assertSame([], $res['categories']);
    }

    public function test_find_intent_with_no_shops_gives_friendly_empty_reply(): void
    {
        $this->fakeClaude('{"intent": "find", "category_id": 3, "reply": "Here are some AC techs 👇"}');
        // No AC Repair (category 3) shops exist.

        $res = $this->withHeader('X-Device-Id', 'dev-ai')
            ->postJson('/api/ai/search', ['message' => 'my AC is broken'])
            ->assertOk()
            ->json();

        $this->assertSame(3, $res['category_id']);
        $this->assertSame([], $res['shops']);
        $this->assertStringContainsString("couldn't find", $res['reply']);
        $this->assertStringContainsString('AC Repair', $res['reply']);
    }

    public function test_unparseable_model_output_falls_back_to_off_topic(): void
    {
        $this->fakeClaude('sorry, I am not sure');

        $res = $this->withHeader('X-Device-Id', 'dev-ai')
            ->postJson('/api/ai/search', ['message' => 'barber'])
            ->assertOk()
            ->json();

        $this->assertNull($res['category_id']);
        $this->assertSame([], $res['shops']);
    }

    public function test_categories_endpoint_lists_only_categories_with_shops(): void
    {
        Shop::factory()->count(2)->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 1]);
        Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 9]);

        $cats = $this->getJson('/api/ai/categories')->assertOk()->json('categories');

        $byId = collect($cats)->keyBy('id');
        $this->assertSame(2, $byId[1]['count']);
        $this->assertTrue($byId->has(9));
        $this->assertFalse($byId->has(2));
    }

    public function test_message_is_required(): void
    {
        $this->withHeader('X-Device-Id', 'dev-ai')
            ->postJson('/api/ai/search', [])
            ->assertStatus(422);
    }
}
