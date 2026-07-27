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

    public function test_a_bare_facebook_url_with_no_path_is_not_salvaged(): void
    {
        $shop = Shop::factory()->create();

        // https://facebook.com/ with no username is un-normalizable (no data loss)
        $out = app(LeadImporter::class)->import($shop, [[
            'name' => 'Acme Gym',
            'website' => 'https://facebook.com/',
            'source' => 'meta_ad_library',
        ]]);

        $lead = $out['saved'][0]->fresh();
        // Salvage only happens when normalization succeeds; the bare URL stays in website
        $this->assertSame('https://facebook.com/', $lead->website, 'un-normalizable URL is not lost');
        $this->assertNull($lead->facebook);
    }

    public function test_reimport_updates_facebook_when_new_website_is_a_facebook_url(): void
    {
        $shop = Shop::factory()->create();

        // First import: normal website from Google Places
        $out1 = app(LeadImporter::class)->import($shop, [[
            'name' => 'Acme Gym',
            'website' => 'https://acmegym.ae',
            'external_ref' => 'ext-123',
            'source' => 'google_places',
        ]]);
        $lead1 = $out1['saved'][0]->fresh();
        $this->assertSame('https://acmegym.ae', $lead1->website);
        $this->assertNull($lead1->facebook);

        // User manually adds a Facebook handle
        $lead1->update(['facebook' => 'https://facebook.com/user-edit']);
        $lead1->refresh();
        $this->assertSame('https://facebook.com/user-edit', $lead1->facebook);

        // Re-import the same lead (same external_ref) with a Facebook URL in website
        // (e.g., AdLibraryService found it later)
        $out2 = app(LeadImporter::class)->import($shop, [[
            'name' => 'Acme Gym',
            'website' => 'https://www.facebook.com/acmebiz',
            'external_ref' => 'ext-123',
            'source' => 'meta_ad_library',
        ]]);
        $lead2 = $out2['saved'][0]->fresh();

        // Re-import overwrites both: website is now cleared, facebook is updated
        // to the new URL from the re-import (this documents the semantics)
        $this->assertNull($lead2->website);
        $this->assertSame('https://facebook.com/acmebiz', $lead2->facebook);
    }
}
