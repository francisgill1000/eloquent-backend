<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_excludes_master_account(): void
    {
        $master = Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => true]);
        $shop = Shop::factory()->create(['status' => Shop::ACTIVE]);

        $ids = collect($this->getJson('/api/shops')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($shop->id));
        $this->assertFalse($ids->contains($master->id));
    }
}
