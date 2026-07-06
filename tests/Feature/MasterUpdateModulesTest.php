<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MasterUpdateModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_can_set_shop_modules(): void
    {
        $master = Shop::create(['name' => 'Master', 'is_master' => true]);
        $target = Shop::create(['name' => 'Target', 'modules' => ['bookings']]);
        Sanctum::actingAs($master, ['*']);

        $res = $this->patchJson("/api/master/shops/{$target->id}", [
            'modules' => ['bookings', 'leads'],
        ]);

        $res->assertOk()->assertJsonPath('data.modules', ['bookings', 'leads']);
        $this->assertSame(['bookings', 'leads'], $target->fresh()->modules);
    }

    public function test_invalid_module_is_rejected(): void
    {
        $master = Shop::create(['name' => 'Master', 'is_master' => true]);
        $target = Shop::create(['name' => 'Target', 'modules' => ['bookings']]);
        Sanctum::actingAs($master, ['*']);

        $this->patchJson("/api/master/shops/{$target->id}", [
            'modules' => ['bookings', 'nonsense'],
        ])->assertStatus(422);
    }
}
