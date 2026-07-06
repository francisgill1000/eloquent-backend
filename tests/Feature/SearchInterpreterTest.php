<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use App\Services\Leads\Contracts\LeadSourceInterface;
use App\Services\Leads\SearchInterpreter;
use App\Services\Wa\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SearchInterpreterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // interpretation is cached; keep tests independent
    }

    private function fakeClaude(string $reply): void
    {
        $fake = new class($reply) extends ClaudeClient {
            public function __construct(public string $reply) {}
            public function reply(string $system, array $history): string { return $this->reply; }
        };
        $this->app->instance(ClaudeClient::class, $fake);
    }

    public function test_interpret_extracts_keyword_and_area(): void
    {
        $this->fakeClaude('{"keyword":"hotels","area":"Dubai"}');
        $shop = Shop::factory()->create(['name' => 'Glow Salon']);

        $out = app(SearchInterpreter::class)->interpret($shop, 'find me some customer for my salon shop', null);

        $this->assertSame('hotels', $out['keyword']);
        $this->assertSame('Dubai', $out['area']);
    }

    public function test_interpret_falls_back_to_raw_area_when_model_omits_it(): void
    {
        $this->fakeClaude('{"keyword":"car wash","area":""}');
        $shop = Shop::factory()->create();

        $out = app(SearchInterpreter::class)->interpret($shop, 'car wash', 'Deira');

        $this->assertSame('car wash', $out['keyword']);
        $this->assertSame('Deira', $out['area']);
    }

    public function test_interpret_throws_on_unparseable_reply(): void
    {
        $this->fakeClaude('sorry, no json');
        $shop = Shop::factory()->create();

        $this->expectException(\RuntimeException::class);
        app(SearchInterpreter::class)->interpret($shop, 'whatever', null);
    }

    // --- Endpoint: the search runs the AI-interpreted keyword ---------------

    /** @return array{0: Shop, 1: string} */
    private function actingShop(): array
    {
        $shop = Shop::factory()->create(['is_master' => true, 'name' => 'Glow Salon']);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();
        return [$shop, $token->plainTextToken];
    }

    public function test_search_endpoint_uses_interpreted_keyword_and_returns_it(): void
    {
        [, $token] = $this->actingShop();
        $this->fakeClaude('{"keyword":"hotels","area":""}');

        // Fake Google source: records the query it was asked for.
        $this->app->instance(LeadSourceInterface::class, new class implements LeadSourceInterface {
            public ?string $seenQuery = null;
            public function search(string $query, ?string $area): array
            {
                $this->seenQuery = $query;
                return [[
                    'name' => 'Grand Hotel', 'phone' => '971501112233', 'address' => 'Dubai',
                    'category' => 'hotel', 'external_ref' => 'place_1', 'source' => 'google_places',
                ]];
            }
            public function key(): string { return 'google_places'; }
        });

        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/shop/leads/search?category=' . urlencode('find me some customer for my salon shop'))
            ->assertOk();

        // The search ran the AI keyword, not the raw sentence, and it's echoed back.
        $this->assertSame('hotels', $res->json('meta.searched_for'));
        $this->assertSame('Grand Hotel', $res->json('data.0.name'));
        $this->assertSame('hotels', app(LeadSourceInterface::class)->seenQuery);
    }
}
