<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadHandlesTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_columns_are_fillable_and_persist(): void
    {
        $shop = Shop::factory()->create();

        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'new', 'source' => 'google',
            'instagram' => 'https://instagram.com/acmegym',
            'facebook' => 'https://facebook.com/acmegym',
            'tiktok' => 'https://tiktok.com/@acmegym',
            'linkedin' => 'https://linkedin.com/company/acme-gym',
            'email' => 'owner@acmegym.ae',
        ]);

        $fresh = $lead->fresh();
        $this->assertSame('https://instagram.com/acmegym', $fresh->instagram);
        $this->assertSame('https://facebook.com/acmegym', $fresh->facebook);
        $this->assertSame('https://tiktok.com/@acmegym', $fresh->tiktok);
        $this->assertSame('https://linkedin.com/company/acme-gym', $fresh->linkedin);
        $this->assertSame('owner@acmegym.ae', $fresh->email);
    }

    public function test_handles_default_to_null(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'new', 'source' => 'google',
        ]);

        foreach (['instagram', 'facebook', 'tiktok', 'linkedin', 'email'] as $field) {
            $this->assertNull($lead->fresh()->{$field}, $field);
        }
    }
}
