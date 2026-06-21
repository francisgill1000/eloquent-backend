<?php

namespace Tests\Feature\Avatar;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopAvatarColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_persists_avatar_and_voice_ids(): void
    {
        $shop = Shop::factory()->create([
            'avatar_id' => 'av_123',
            'voice_id'  => 'vo_456',
        ]);

        $this->assertSame('av_123', $shop->fresh()->avatar_id);
        $this->assertSame('vo_456', $shop->fresh()->voice_id);
    }
}
