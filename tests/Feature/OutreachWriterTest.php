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

    public function test_templates_for_shop_parses_json_and_keeps_placeholders(): void
    {
        $this->fakeClaude('{"opening":"Hi {name}, from {shop}","followup":"Following up, {name}"}');
        $shop = Shop::factory()->create(['name' => 'Marina Spa']);

        $out = app(OutreachWriter::class)->templatesForShop($shop);

        $this->assertSame('Hi {name}, from {shop}', $out['opening']);
        $this->assertSame('Following up, {name}', $out['followup']);
    }

    public function test_templates_for_shop_tolerates_json_wrapped_in_prose(): void
    {
        // Model sometimes adds a sentence around the JSON — we extract the object.
        $this->fakeClaude('Sure! {"opening":"Hi {name}","followup":"Ping {name}"} Hope that helps.');
        $shop = Shop::factory()->create(['name' => 'Acme']);

        $out = app(OutreachWriter::class)->templatesForShop($shop);

        $this->assertSame('Hi {name}', $out['opening']);
        $this->assertSame('Ping {name}', $out['followup']);
    }

    public function test_templates_for_shop_throws_on_unparseable_reply(): void
    {
        $this->fakeClaude('no json here');
        $shop = Shop::factory()->create();

        $this->expectException(\RuntimeException::class);
        app(OutreachWriter::class)->templatesForShop($shop);
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
}
