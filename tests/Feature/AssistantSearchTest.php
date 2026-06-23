<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
    }

    private function fakeTurns(array $bodies): void
    {
        $seq = Http::fakeSequence('api.anthropic.com/v1/messages');
        foreach ($bodies as $b) {
            $seq->push($b, 200);
        }
    }

    public function test_returns_reply_and_shops_for_a_search(): void
    {
        Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 1, 'name' => 'Sharp Cuts']);

        $this->fakeTurns([
            ['content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'search_shops', 'input' => (object) ['category_id' => 1]]]],
            ['content' => [['type' => 'text', 'text' => 'Here are some barbers 👇']]],
        ]);

        $res = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->postJson('/api/ai/search', ['messages' => [['role' => 'user', 'content' => 'find a barber']]]);

        $res->assertOk()
            ->assertJsonPath('reply', 'Here are some barbers 👇')
            ->assertJsonPath('shops.0.name', 'Sharp Cuts')
            ->assertJsonPath('action', null);
    }

    public function test_returns_navigate_action(): void
    {
        $this->fakeTurns([
            ['content' => [['type' => 'text', 'text' => 'Opening your favourites!'], ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'navigate', 'input' => (object) ['route' => '/favourites']]]],
        ]);

        $res = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->postJson('/api/ai/search', ['messages' => [['role' => 'user', 'content' => 'open my favourites']]]);

        $res->assertOk()
            ->assertJsonPath('action.type', 'navigate')
            ->assertJsonPath('action.route', '/favourites');
    }

    public function test_still_accepts_legacy_single_message(): void
    {
        $this->fakeTurns([['content' => [['type' => 'text', 'text' => 'Hello!']]]]);

        $res = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->postJson('/api/ai/search', ['message' => 'hi']);

        $res->assertOk()->assertJsonPath('reply', 'Hello!');
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
}
