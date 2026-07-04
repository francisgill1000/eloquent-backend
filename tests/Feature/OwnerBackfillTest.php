<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use App\Support\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OwnerBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_creates_owner_user_and_role(): void
    {
        $shop = Shop::factory()->create(['pin' => '3131']);

        Artisan::call('rbac:backfill-owners');

        $owner = ShopUser::where('shop_id', $shop->id)->where('login_pin', '3131')->first();
        $this->assertNotNull($owner);

        setPermissionsTeamId($shop->id);
        $owner->load('roles');
        $this->assertTrue(Rbac::isOwner($owner));
    }

    public function test_backfill_is_idempotent(): void
    {
        Shop::factory()->create(['pin' => '2020']);

        Artisan::call('rbac:backfill-owners');
        Artisan::call('rbac:backfill-owners');

        $this->assertSame(1, ShopUser::where('login_pin', '2020')->count());
    }
}
