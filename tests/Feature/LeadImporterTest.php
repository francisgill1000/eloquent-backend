<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Services\Leads\LeadImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadImporterTest extends TestCase
{
    use RefreshDatabase;

    /** A concurrent import can insert the same (shop_id, external_ref) row
     *  after our firstOrNew() SELECT ran but before our own save() lands —
     *  the loser must recover by updating the now-existing row instead of
     *  surfacing an uncaught duplicate-key error. */
    public function test_recovers_when_a_concurrent_import_wins_the_same_external_ref(): void
    {
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $existing = Lead::create([
            'shop_id' => $shop->id,
            'external_ref' => 'place_race',
            'name' => 'Won The Race Barbers',
            'status' => 'new',
            'source' => 'manual',
        ]);

        // Simulate the losing request: its firstOrNew() ran before $existing
        // was committed, so it built a fresh, not-yet-existing model for a
        // key that has since been taken.
        $stale = new Lead(['shop_id' => $shop->id, 'external_ref' => 'place_race']);

        $importer = new LeadImporter();
        $lead = $importer->saveDeduped(
            $stale,
            ['name' => 'From This Request', 'source' => 'google_places'],
            $shop->id,
            'place_race',
        );

        $this->assertTrue($lead->exists);
        $this->assertSame($existing->id, $lead->id);
        $this->assertSame('From This Request', $lead->fresh()->name);
        $this->assertSame(1, Lead::where('shop_id', $shop->id)->where('external_ref', 'place_race')->count());
    }
}
