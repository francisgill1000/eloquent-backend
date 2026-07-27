<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Leads\LeadImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadImporterFacebookTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_facebook_page_website_becomes_the_facebook_handle(): void
    {
        $shop = Shop::factory()->create();

        $out = app(LeadImporter::class)->import($shop, [[
            'name' => 'Acme Gym',
            'website' => 'https://www.facebook.com/acmegym',
            'source' => 'meta_ad_library',
        ]]);

        $lead = $out['saved'][0]->fresh();
        $this->assertSame('https://facebook.com/acmegym', $lead->facebook);
        $this->assertNull($lead->website, 'a page URL is a channel, not a website');
    }

    public function test_a_real_website_is_left_alone(): void
    {
        $shop = Shop::factory()->create();

        $out = app(LeadImporter::class)->import($shop, [[
            'name' => 'Acme Gym',
            'website' => 'https://acmegym.ae',
            'source' => 'google_places',
        ]]);

        $lead = $out['saved'][0]->fresh();
        $this->assertSame('https://acmegym.ae', $lead->website);
        $this->assertNull($lead->facebook);
    }

    public function test_a_lead_with_no_website_imports_cleanly(): void
    {
        $shop = Shop::factory()->create();

        $out = app(LeadImporter::class)->import($shop, [[
            'name' => 'Acme Gym', 'source' => 'google_places',
        ]]);

        $lead = $out['saved'][0]->fresh();
        $this->assertNull($lead->website);
        $this->assertNull($lead->facebook);
    }
}
