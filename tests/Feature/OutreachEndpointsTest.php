<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Services\Wa\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutreachEndpointsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: string} [shop, plainTextToken] */
    private function actingShop(): array
    {
        $shop = Shop::factory()->create(['is_master' => true, 'name' => 'Marina Spa']);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        setPermissionsTeamId($shop->id);
        $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]
        ));
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();
        return [$shop, $token->plainTextToken];
    }

    private function auth(string $token): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    private function fakeClaude(string $reply): void
    {
        $fake = new class($reply) extends ClaudeClient {
            public function __construct(public string $reply) {}
            public function reply(string $system, array $history): string { return $this->reply; }
        };
        $this->app->instance(ClaudeClient::class, $fake);
    }

    public function test_personalize_returns_message_for_lead(): void
    {
        [$shop, $token] = $this->actingShop();
        $this->fakeClaude('Hi Pak Cargo, quick demo?');
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Pak Cargo', 'phone' => '0501112233',
            'status' => 'new', 'source' => 'google',
        ]);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/personalize", ['kind' => 'opening'])
            ->assertOk()
            ->assertJson(['message' => 'Hi Pak Cargo, quick demo?', 'kind' => 'opening']);
    }

    public function test_personalize_is_tenant_scoped(): void
    {
        [, $token] = $this->actingShop();
        $this->fakeClaude('x');
        $other = Shop::factory()->create(['is_master' => true]);
        $lead = Lead::create([
            'shop_id' => $other->id, 'name' => 'Not Mine', 'phone' => '0501112233',
            'status' => 'new', 'source' => 'google',
        ]);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/personalize", ['kind' => 'opening'])
            ->assertNotFound();
    }

    public function test_personalize_validates_kind(): void
    {
        [$shop, $token] = $this->actingShop();
        $this->fakeClaude('x');
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'phone' => '0501112233',
            'status' => 'new', 'source' => 'google',
        ]);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/personalize", ['kind' => 'bogus'])
            ->assertStatus(422);
    }
}
