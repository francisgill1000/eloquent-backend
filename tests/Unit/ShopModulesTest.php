<?php

namespace Tests\Unit;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_shop_defaults_to_bookings_module(): void
    {
        $shop = Shop::create(['name' => 'Test']);
        $this->assertSame(['bookings'], $shop->fresh()->modules);
    }

    public function test_has_module_reflects_the_array(): void
    {
        $shop = Shop::create(['name' => 'Test', 'modules' => ['bookings', 'leads']]);
        $this->assertTrue($shop->hasModule('leads'));
        $this->assertTrue($shop->hasModule('bookings'));

        $bookingsOnly = Shop::create(['name' => 'B', 'modules' => ['bookings']]);
        $this->assertFalse($bookingsOnly->hasModule('leads'));
    }
}
