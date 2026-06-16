<?php

namespace Tests\Feature;

use App\Models\ParentCategory;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentCategoryTest extends TestCase
{
    use RefreshDatabase;

    /** Authenticate as a shop the way bizrezzy does: a real Sanctum bearer token. */
    private function actAsShop(Shop $shop): void
    {
        $token = $shop->createToken('test')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_shop_can_create_and_list_parent_categories(): void
    {
        $shop = Shop::factory()->create();
        $this->actAsShop($shop);

        $this->postJson('/api/shop/parent-categories', ['name' => 'Massage'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Massage');

        $this->getJson('/api/shop/parent-categories')
            ->assertStatus(200)
            ->assertJsonCount(1);
    }

    public function test_parent_category_name_is_unique_per_shop(): void
    {
        $shop = Shop::factory()->create();
        $this->actAsShop($shop);
        ParentCategory::create(['shop_id' => $shop->id, 'name' => 'Massage']);

        $this->postJson('/api/shop/parent-categories', ['name' => 'Massage'])
            ->assertStatus(422);
    }

    public function test_catalog_create_returns_nested_parent_category(): void
    {
        $shop = Shop::factory()->create();
        $this->actAsShop($shop);
        $cat = ParentCategory::create(['shop_id' => $shop->id, 'name' => 'Body Massage']);

        $this->postJson('/api/shop/catalogs', [
            'title' => 'Relax Me Massage',
            'price' => 160,
            'parent_category_id' => $cat->id,
        ])->assertStatus(201)
            ->assertJsonPath('data.parent_category.name', 'Body Massage');

        $this->getJson('/api/shop/catalogs')
            ->assertStatus(200)
            ->assertJsonPath('0.parent_category.name', 'Body Massage');
    }

    public function test_shop_cannot_attach_another_shops_parent_category(): void
    {
        $shopA = Shop::factory()->create();
        $shopB = Shop::factory()->create();
        $catA = ParentCategory::create(['shop_id' => $shopA->id, 'name' => 'Massage']);

        // Shop B tries to attach shop A's category.
        $this->actAsShop($shopB);
        $this->postJson('/api/shop/catalogs', [
            'title' => 'Sneaky Service',
            'price' => 50,
            'parent_category_id' => $catA->id,
        ])->assertStatus(422);
    }

    public function test_catalog_without_parent_category_still_works(): void
    {
        $shop = Shop::factory()->create();
        $this->actAsShop($shop);

        $this->postJson('/api/shop/catalogs', [
            'title' => 'Plain Service',
            'price' => 30,
        ])->assertStatus(201)
            ->assertJsonPath('data.parent_category', null);
    }

    public function test_shop_can_update_a_parent_category_name(): void
    {
        $shop = Shop::factory()->create();
        $this->actAsShop($shop);
        $cat = ParentCategory::create(['shop_id' => $shop->id, 'name' => 'Massage']);

        $this->putJson("/api/shop/parent-categories/{$cat->id}", ['name' => 'Spa Massage'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Spa Massage');
    }

    public function test_deleting_a_parent_category_uncategorises_its_services(): void
    {
        $shop = Shop::factory()->create();
        $this->actAsShop($shop);
        $cat = ParentCategory::create(['shop_id' => $shop->id, 'name' => 'Massage']);
        $catalog = $shop->catalogs()->create([
            'title' => 'Head Oil Massage', 'price' => 70, 'parent_category_id' => $cat->id,
        ]);

        $this->deleteJson("/api/shop/parent-categories/{$cat->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('parent_categories', ['id' => $cat->id]);
        $this->assertNull($catalog->fresh()->parent_category_id);
    }

    public function test_shop_cannot_update_another_shops_parent_category(): void
    {
        $shopA = Shop::factory()->create();
        $shopB = Shop::factory()->create();
        $catA = ParentCategory::create(['shop_id' => $shopA->id, 'name' => 'Massage']);

        $this->actAsShop($shopB);
        $this->putJson("/api/shop/parent-categories/{$catA->id}", ['name' => 'Hijacked'])
            ->assertStatus(404);
    }
}
