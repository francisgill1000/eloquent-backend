<?php
namespace Tests\Unit;

use App\Models\Lead;
use App\Models\Shop;
use App\Services\Leads\LeadImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadImporterTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create(['name' => 'S', 'shop_code' => '7300', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['leads']]);
    }

    public function test_import_creates_new_leads_and_counts_created(): void
    {
        $shop = $this->shop();
        $out = app(LeadImporter::class)->import($shop, [
            ['name' => 'Gym One', 'external_ref' => 'g1', 'phone' => '0501112222'],
            ['name' => 'Gym Two', 'external_ref' => 'g2'],
        ]);

        $this->assertSame(2, $out['created']);
        $this->assertCount(2, $out['saved']);
        $this->assertSame(2, Lead::forShop($shop->id)->count());
        $this->assertSame('new', Lead::forShop($shop->id)->first()->status);
    }

    public function test_import_dedupes_on_external_ref(): void
    {
        $shop = $this->shop();
        $importer = app(LeadImporter::class);
        $importer->import($shop, [['name' => 'Gym One', 'external_ref' => 'g1']]);
        $out = $importer->import($shop, [['name' => 'Gym One (renamed)', 'external_ref' => 'g1']]);

        $this->assertSame(0, $out['created']); // updated, not cloned
        $this->assertSame(1, Lead::forShop($shop->id)->count());
        $this->assertSame('Gym One (renamed)', Lead::forShop($shop->id)->first()->name);
    }
}
