<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Services\Leads\Contracts\LeadSourceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadFinderTest extends TestCase
{
    use RefreshDatabase;

    /** A fake source so tests never touch Google. */
    private function fakeSource(array $results): void
    {
        $this->app->instance(LeadSourceInterface::class, new class($results) implements LeadSourceInterface {
            public function __construct(private array $results)
            {
            }

            public function search(string $query, ?string $area): array
            {
                return $this->results;
            }

            public function key(): string
            {
                return 'google_places';
            }
        });
    }

    private function sampleResults(): array
    {
        return [
            [
                'name' => 'Marina Barbers',
                'phone' => '050 123 4567',
                'website' => 'https://marinabarbers.ae',
                'address' => 'Dubai Marina',
                'category' => 'hair_care',
                'lat' => 25.08,
                'lng' => 55.14,
                'rating' => 4.6,
                'external_ref' => 'place_A',
                'source' => 'google_places',
            ],
            [
                'name' => 'JBR Nails',
                'phone' => '971529998888',
                'website' => null,
                'address' => 'JBR',
                'category' => 'beauty_salon',
                'lat' => 25.07,
                'lng' => 55.13,
                'rating' => 4.2,
                'external_ref' => 'place_B',
                'source' => 'google_places',
            ],
        ];
    }

    /** A real (non-master) shop with an active trial + the leads module, so it
     *  clears both subscription.active and module:leads; plus a token tagged to
     *  a ShopUser. Non-master is deliberate: leads are tenant-scoped, so
     *  tenant-isolation must actually hold. */
    private function actingShop(): array
    {
        $shop = Shop::factory()->trialing()->create(['modules' => ['bookings', 'leads']]);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        // Owner bypasses the WS2 can.perm:leads.* gates on the Hunt routes.
        setPermissionsTeamId($shop->id);
        $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]
        ));
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();

        return [$shop, $user, $token->plainTextToken];
    }

    private function auth(string $token): self
    {
        // Reset the resolved guard so each token switch re-authenticates from
        // the Bearer header. The sanctum guard caches the first user across
        // requests within a single test, which would otherwise make a second
        // shop's request run as the first shop (breaking tenant-scoping checks).
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    public function test_full_flow_search_save_status_and_activity_log(): void
    {
        $this->fakeSource($this->sampleResults());
        [$shop, $user, $token] = $this->actingShop();
        app(\App\Services\Credits\HuntCreditService::class)->grant($shop, 5);

        // 1. Search — normalized results, live call spends 1 credit (5 -> 4).
        $search = $this->auth($token)
            ->getJson('/api/shop/leads/search?category=salon&area=Dubai%20Marina')
            ->assertOk();
        $search->assertJsonPath('meta.from_cache', false);
        $search->assertJsonPath('meta.credits', 4);
        $this->assertCount(2, $search->json('data'));

        // 2. Repeat identical search — served from cache, NO credit spent.
        $repeat = $this->auth($token)
            ->getJson('/api/shop/leads/search?category=salon&area=Dubai%20Marina')
            ->assertOk();
        $repeat->assertJsonPath('meta.from_cache', true);
        $repeat->assertJsonPath('meta.credits', 4);
        $this->assertDatabaseCount('lead_search_logs', 1);

        // 3. Save the two results under a named pipeline.
        $this->auth($token)
            ->postJson('/api/shop/leads', ['leads' => $this->sampleResults(), 'pipeline' => 'Marina salons'])
            ->assertCreated();
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseHas('leads', [
            'shop_id' => $shop->id, 'external_ref' => 'place_A', 'status' => 'new', 'pipeline' => 'Marina salons',
        ]);

        // Dedupe: saving again does not clone.
        $this->auth($token)
            ->postJson('/api/shop/leads', ['leads' => $this->sampleResults(), 'pipeline' => 'Marina salons'])
            ->assertCreated();
        $this->assertDatabaseCount('leads', 2);

        // 4. Status update writes an activity row + bumps last_contacted_at.
        $lead = Lead::where('external_ref', 'place_A')->firstOrFail();
        $this->auth($token)
            ->patchJson("/api/shop/leads/{$lead->id}/status", ['status' => 'sent', 'note' => 'WhatsApped'])
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $lead->refresh();
        $this->assertNotNull($lead->last_contacted_at);
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'type' => LeadActivity::TYPE_STATUS_CHANGE,
            'user_id' => $user->id,
        ]);
    }

    public function test_saving_requires_a_pipeline_name(): void
    {
        [, , $token] = $this->actingShop();

        // No pipeline → 422; nothing is saved.
        $this->auth($token)
            ->postJson('/api/shop/leads', ['leads' => $this->sampleResults()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pipeline');
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_index_filters_by_pipeline_and_lists_pipelines(): void
    {
        [$shop, , $token] = $this->actingShop();
        Lead::factory()->count(2)->create(['shop_id' => $shop->id, 'pipeline' => 'Salons']);
        Lead::factory()->create(['shop_id' => $shop->id, 'pipeline' => 'Gyms']);

        // Distinct pipeline names come back sorted for the group filter.
        $this->auth($token)
            ->getJson('/api/shop/leads')
            ->assertOk()
            ->assertJsonPath('pipelines', ['Gyms', 'Salons']);

        // Filtering narrows to a single pipeline.
        $this->auth($token)
            ->getJson('/api/shop/leads?pipeline=Salons')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_followup_is_a_valid_status_transition(): void
    {
        [$shop, , $token] = $this->actingShop();
        $lead = Lead::factory()->create(['shop_id' => $shop->id, 'status' => 'sent']);

        $this->auth($token)
            ->patchJson("/api/shop/leads/{$lead->id}/status", ['status' => 'followup'])
            ->assertOk()
            ->assertJsonPath('data.status', 'followup');
    }

    public function test_enrichment_accessors(): void
    {
        $lead = new Lead(['phone' => '050 123 4567', 'lat' => 25.0, 'lng' => 55.0]);

        $this->assertTrue($lead->is_mobile);
        $this->assertSame('https://wa.me/971501234567', $lead->whatsapp_url);
        $this->assertSame('tel:+971501234567', $lead->tel_url);
        $this->assertStringContainsString('query=25,55', $lead->map_url);

        $landline = new Lead(['phone' => '04 123 4567']);
        $this->assertFalse($landline->is_mobile);
    }

    public function test_leads_are_tenant_scoped(): void
    {
        $this->fakeSource($this->sampleResults());
        [$shopA, , $tokenA] = $this->actingShop();
        [$shopB, , $tokenB] = $this->actingShop();

        // Shop A saves a lead.
        $this->auth($tokenA)
            ->postJson('/api/shop/leads', ['leads' => [$this->sampleResults()[0]], 'pipeline' => 'A-list'])
            ->assertCreated();
        $leadA = Lead::where('shop_id', $shopA->id)->firstOrFail();

        // Shop B sees none of A's leads.
        $this->auth($tokenB)
            ->getJson('/api/shop/leads')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Shop B cannot mutate A's lead.
        $this->auth($tokenB)
            ->patchJson("/api/shop/leads/{$leadA->id}/status", ['status' => 'won'])
            ->assertNotFound();

        // A's lead is untouched.
        $this->assertSame('new', $leadA->fresh()->status);
    }

    public function test_index_returns_funnel_counts(): void
    {
        [$shop, , $token] = $this->actingShop();
        Lead::factory()->count(2)->create(['shop_id' => $shop->id, 'status' => 'new']);
        Lead::factory()->create(['shop_id' => $shop->id, 'status' => 'won']);

        $this->auth($token)
            ->getJson('/api/shop/leads')
            ->assertOk()
            ->assertJsonPath('funnel.new', 2)
            ->assertJsonPath('funnel.won', 1)
            ->assertJsonPath('funnel.pass', 0);
    }

    public function test_search_blocks_when_credits_exhausted(): void
    {
        $this->fakeSource($this->sampleResults());
        [$shop, , $token] = $this->actingShop();
        app(\App\Services\Credits\HuntCreditService::class)->grant($shop, 1);

        // First (novel) search spends the only credit.
        $this->auth($token)
            ->getJson('/api/shop/leads/search?category=salon')
            ->assertOk();

        // A different (novel) search misses cache and is blocked with 429
        // (429, not 402: 402 is reserved for the Lens subscription paywall).
        $this->auth($token)
            ->getJson('/api/shop/leads/search?category=spa')
            ->assertStatus(429)
            ->assertJsonPath('error', 'insufficient_credits');
    }
}
