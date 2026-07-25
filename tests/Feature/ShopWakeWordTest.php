<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShopWakeWordTest extends TestCase
{
    use RefreshDatabase;

    /** Owner token: full permissions for this shop's team. */
    private function actingOwner(Shop $shop): string
    {
        setPermissionsTeamId($shop->id);
        $owner = Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($owner);
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();
        return $new->plainTextToken;
    }

    /** A user whose role holds `summary.view` but NOT `settings.manage`. */
    private function actingViewer(Shop $shop): string
    {
        (new PermissionSeeder())->run();
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Viewer', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->givePermissionTo('summary.view');
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($role);
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();
        return $new->plainTextToken;
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer $token"];
    }

    public function test_get_falls_back_to_the_shop_name_when_unset(): void
    {
        // The default must come from the shop's own name — never a hardcoded one.
        $shop = Shop::factory()->create(['name' => 'Northside Barbers']);
        $token = $this->actingOwner($shop);

        $this->withHeaders($this->auth($token))->getJson('/api/shop/wake-word')
            ->assertOk()
            ->assertJsonPath('phrase', null)
            ->assertJsonPath('effective_phrase', 'Northside Barbers')
            ->assertJsonPath('using_custom', false);
    }

    public function test_put_saves_a_custom_phrase(): void
    {
        $shop = Shop::factory()->create(['name' => 'Northside Barbers']);
        $token = $this->actingOwner($shop);

        $this->withHeaders($this->auth($token))
            ->putJson('/api/shop/wake-word', ['phrase' => '  Northside  '])
            ->assertOk()
            ->assertJsonPath('phrase', 'Northside')      // trimmed on the way in
            ->assertJsonPath('effective_phrase', 'Northside')
            ->assertJsonPath('using_custom', true);

        $this->assertSame('Northside', $shop->fresh()->wake_phrase);
    }

    public function test_put_empty_clears_back_to_the_shop_name(): void
    {
        $shop = Shop::factory()->create(['name' => 'Northside Barbers']);
        $token = $this->actingOwner($shop);
        $this->withHeaders($this->auth($token))->putJson('/api/shop/wake-word', ['phrase' => 'Northside'])->assertOk();

        $this->withHeaders($this->auth($token))
            ->putJson('/api/shop/wake-word', ['phrase' => '   '])
            ->assertOk()
            ->assertJsonPath('phrase', null)
            ->assertJsonPath('effective_phrase', 'Northside Barbers');
    }

    public function test_put_rejects_a_phrase_under_three_characters(): void
    {
        // A 1-2 character phrase would fire on ordinary conversation.
        $shop = Shop::factory()->create();
        $token = $this->actingOwner($shop);

        $this->withHeaders($this->auth($token))
            ->putJson('/api/shop/wake-word', ['phrase' => 'ab'])
            ->assertStatus(422);
    }

    public function test_put_rejects_a_phrase_over_sixty_characters(): void
    {
        $shop = Shop::factory()->create();
        $token = $this->actingOwner($shop);

        $this->withHeaders($this->auth($token))
            ->putJson('/api/shop/wake-word', ['phrase' => str_repeat('a', 61)])
            ->assertStatus(422);
    }

    public function test_get_is_allowed_without_settings_manage(): void
    {
        // The AI Summary page needs the phrase; summary.view users do not hold
        // settings.manage, and a business name is not sensitive.
        $shop = Shop::factory()->create(['name' => 'Northside Barbers']);
        $token = $this->actingViewer($shop);

        $this->withHeaders($this->auth($token))->getJson('/api/shop/wake-word')
            ->assertOk()
            ->assertJsonPath('effective_phrase', 'Northside Barbers');
    }

    public function test_put_requires_settings_manage(): void
    {
        $shop = Shop::factory()->create();
        $token = $this->actingViewer($shop);

        $this->withHeaders($this->auth($token))
            ->putJson('/api/shop/wake-word', ['phrase' => 'Northside'])
            ->assertStatus(403);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/shop/wake-word')->assertStatus(401);
    }

    public function test_one_shop_never_reads_or_writes_another_shops_phrase(): void
    {
        $a = Shop::factory()->create(['name' => 'Shop A']);
        $b = Shop::factory()->create(['name' => 'Shop B']);
        $tokenA = $this->actingOwner($a);
        $this->actingOwner($b);

        // The shop comes from the token; there is no shop_id input to abuse.
        $this->withHeaders($this->auth($tokenA))
            ->putJson('/api/shop/wake-word', ['phrase' => 'Alpha'])
            ->assertOk();

        $this->assertSame('Alpha', $a->fresh()->wake_phrase);
        $this->assertNull($b->fresh()->wake_phrase);
    }
}
