<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_without_leads_module_is_forbidden_from_leads_index(): void
    {
        $shop = Shop::create(['name' => 'BookingsOnly', 'modules' => ['bookings']]);
        // Active subscription so the request clears the paywall (subscription.active
        // runs before module:leads) and actually reaches the module gate.
        $shop->subscription()->create([
            'status' => 'active',
            'plan' => 'monthly',
            'access_until' => now()->addMonth(),
        ]);
        Sanctum::actingAs($shop, ['*']);

        $this->getJson('/api/shop/leads')
            ->assertStatus(403)
            ->assertJson(['error' => 'module_not_enabled', 'module' => 'leads']);
    }

    public function test_shop_with_leads_module_passes_the_gate(): void
    {
        $shop = Shop::create(['name' => 'HasLeads', 'modules' => ['bookings', 'leads']]);
        $shop->subscription()->create([
            'status' => 'active',
            'plan' => 'monthly',
            'access_until' => now()->addMonth(),
        ]);
        Sanctum::actingAs($shop, ['*']);

        // The gate lets it through to the controller (not a 403).
        $this->getJson('/api/shop/leads')->assertStatus(200);
    }
}
