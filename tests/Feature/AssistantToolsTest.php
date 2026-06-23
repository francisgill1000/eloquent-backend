<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\GuestFavourite;
use App\Models\Shop;
use App\Models\User;
use App\Services\Ai\AssistantTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantToolsTest extends TestCase
{
    use RefreshDatabase;

    private function tools(string $device = 'dev-1', ?User $user = null): AssistantTools
    {
        return new AssistantTools($device, $user, null, null);
    }

    public function test_list_categories_returns_only_categories_with_shops(): void
    {
        Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 1]);

        $json = $this->tools()->executeRead('list_categories', []);
        $data = json_decode($json, true);

        $names = array_column($data['categories'], 'name');
        $this->assertContains('Barber', $names);
    }

    public function test_list_favourites_is_device_scoped(): void
    {
        $mine = Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false]);
        $other = Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false]);
        GuestFavourite::create(['device_id' => 'dev-1', 'shop_id' => $mine->id]);
        GuestFavourite::create(['device_id' => 'dev-2', 'shop_id' => $other->id]);

        $data = json_decode($this->tools('dev-1')->executeRead('list_favourites', []), true);

        $ids = array_column($data['favourites'], 'id');
        $this->assertEquals([$mine->id], $ids);
    }

    public function test_list_bookings_is_device_scoped_and_filters_scope(): void
    {
        $shop = Shop::factory()->create();
        $upcoming = Booking::factory()->create([
            'shop_id' => $shop->id, 'device_id' => 'dev-1',
            'date' => now('Asia/Dubai')->addDay()->toDateString(), 'status' => 'booked',
        ]);
        Booking::factory()->create([
            'shop_id' => $shop->id, 'device_id' => 'dev-2',
            'date' => now('Asia/Dubai')->addDay()->toDateString(), 'status' => 'booked',
        ]);

        $data = json_decode($this->tools('dev-1')->executeRead('list_bookings', ['scope' => 'upcoming']), true);

        $refs = array_column($data['bookings'], 'reference');
        $this->assertEquals([$upcoming->booking_reference], $refs);
    }

    public function test_get_account_signals_not_logged_in_for_guest(): void
    {
        $data = json_decode($this->tools()->executeRead('get_account', []), true);
        $this->assertFalse($data['logged_in']);
    }

    public function test_get_account_returns_profile_when_logged_in(): void
    {
        $user = User::factory()->create(['name' => 'Aisha', 'phone' => '0501234567']);
        $data = json_decode($this->tools('dev-1', $user)->executeRead('get_account', []), true);

        $this->assertTrue($data['logged_in']);
        $this->assertEquals('Aisha', $data['name']);
    }

    public function test_search_shops_stashes_full_shop_rows(): void
    {
        Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 1, 'name' => 'Sharp Cuts']);

        $tools = $this->tools();
        $tools->executeRead('search_shops', ['category_id' => 1]);

        $this->assertSame('Sharp Cuts', $tools->collectedShops()->first()->name);
    }

    public function test_search_shops_matches_on_individual_words(): void
    {
        // Voice gave "Hina Salon"; the real shop is "Heena Salon" — the word
        // "Salon" must still surface it.
        Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 9, 'name' => 'Heena Salon']);

        $tools = $this->tools();
        $data = json_decode($tools->executeRead('search_shops', ['query' => 'Hina Salon']), true);

        $names = array_column($data['shops'], 'name');
        $this->assertContains('Heena Salon', $names);
    }

    public function test_defs_include_read_and_action_tools(): void
    {
        $names = array_column(AssistantTools::defs(), 'name');
        foreach (['list_favourites', 'list_bookings', 'get_account', 'search_shops', 'get_shop', 'list_categories', 'navigate', 'register', 'login'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }
}
