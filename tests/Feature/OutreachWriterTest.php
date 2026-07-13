<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Services\Leads\OutreachWriter;
use App\Services\Wa\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutreachWriterTest extends TestCase
{
    use RefreshDatabase;

    /** Bind a fake ClaudeClient that echoes a canned reply and records the prompt. */
    private function fakeClaude(string $reply): object
    {
        $fake = new class($reply) extends ClaudeClient {
            public string $lastSystem = '';
            public function __construct(public string $reply) {}
            public function reply(string $system, array $history): string
            {
                $this->lastSystem = $system;
                return $this->reply;
            }
        };
        $this->app->instance(ClaudeClient::class, $fake);
        return $fake;
    }

    public function test_personalize_for_lead_returns_message_and_includes_lead_in_prompt(): void
    {
        $fake = $this->fakeClaude('Hi Pak Cargo, saw you ship across Dubai — quick demo?');
        $shop = Shop::factory()->create(['name' => 'Eloquent']);
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Pak Cargo', 'phone' => '0501112233',
            'category' => 'cargo', 'address' => 'Deira', 'status' => 'new', 'source' => 'google',
        ]);

        $msg = app(OutreachWriter::class)->personalizeForLead($shop, $lead, 'opening');

        $this->assertStringContainsString('Pak Cargo', $msg);
        // The lead's real details were put into the prompt the model saw.
        $this->assertStringContainsString('Pak Cargo', $fake->lastSystem);
        $this->assertStringContainsString('Cargo', $fake->lastSystem);
        // The prompt must forbid asking for a contact person's name (cold B2B
        // outreach addresses the business by its business name).
        $this->assertStringContainsString('business name', $fake->lastSystem);
        $this->assertStringContainsString('not needed', $fake->lastSystem);
    }

    public function test_followup_prompt_is_self_contained_and_never_references_a_prior_message(): void
    {
        $fake = $this->fakeClaude('Hi Pak Cargo — still worth a quick chat?');
        $shop = Shop::factory()->create(['name' => 'Eloquent']);
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Pak Cargo', 'phone' => '0501112233',
            'category' => 'cargo', 'address' => 'Deira', 'status' => 'sent', 'source' => 'google',
        ]);

        app(OutreachWriter::class)->personalizeForLead($shop, $lead, 'followup');

        // The follow-up must NOT assume a prior opening message the model was
        // never given (the bug: it asked the user for that message instead of
        // writing). No phantom-opening framing.
        $this->assertStringNotContainsStringIgnoringCase('repeat the opening', $fake->lastSystem);
        $this->assertStringNotContainsStringIgnoringCase('never repeat', $fake->lastSystem);
        // It should frame the nudge honestly: already contacted, no reply yet.
        $this->assertStringContainsStringIgnoringCase("hasn't replied", $fake->lastSystem);
        // The discovery hook source (category + area) still reaches the prompt.
        $this->assertStringContainsString('Cargo', $fake->lastSystem);
        $this->assertStringContainsString('Deira', $fake->lastSystem);
    }

    public function test_catalog_item_descriptions_reach_the_prompt(): void
    {
        $fake = $this->fakeClaude('Hello Pak Cargo 👋');
        $shop = Shop::factory()->create(['name' => 'Eloquent']);
        $shop->catalogs()->create([
            'title' => 'Business Hunt',
            'description' => 'Find the right businesses to approach by category and area',
            'price' => 199,
        ]);
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Pak Cargo', 'phone' => '0501112233',
            'category' => 'cargo', 'address' => 'Deira', 'status' => 'new', 'source' => 'google',
        ]);

        app(OutreachWriter::class)->personalizeForLead($shop, $lead, 'opening');

        // Bullets need substance: the item's description, not just its title.
        $this->assertStringContainsString('Business Hunt', $fake->lastSystem);
        $this->assertStringContainsString('by category and area', $fake->lastSystem);
    }

    public function test_opening_prompt_permits_the_warm_bulleted_format(): void
    {
        $fake = $this->fakeClaude('Hello 👋');
        $shop = Shop::factory()->create(['name' => 'Eloquent']);
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Pak Cargo', 'phone' => '0501112233',
            'category' => 'cargo', 'address' => 'Deira', 'status' => 'new', 'source' => 'google',
        ]);

        app(OutreachWriter::class)->personalizeForLead($shop, $lead, 'opening');

        // The upgraded rules explicitly allow the ✅ bullet format.
        $this->assertStringContainsString('✅', $fake->lastSystem);
    }
}
