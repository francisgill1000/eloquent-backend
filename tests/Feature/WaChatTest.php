<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\WaAccount;
use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WaChatTest extends TestCase
{
    use RefreshDatabase;

    private function makeAccount(Shop $shop, string $phoneNumberId = 'pn_123'): WaAccount
    {
        return WaAccount::create([
            'shop_id' => $shop->id,
            'phone_number' => '+971500000001',
            'phone_number_id' => $phoneNumberId,
            'waba_id' => 'waba_1',
            'token' => 'test-token-abcd',
        ]);
    }

    /** Authenticate as a shop the way bizrezzy does: a real Sanctum bearer token. */
    private function authed(Shop $shop): void
    {
        $token = $shop->createToken('test')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function webhookPayload(string $phoneNumberId, string $from = '971501112222', string $text = 'Hello', string $msgId = 'wamid.1'): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Ali']]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $msgId,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    public function test_webhook_verify_handshake(): void
    {
        config(['services.whatsapp.verify_token' => 'secret-token']);

        $response = $this->get('/api/wa/webhook?hub.mode=subscribe&hub.verify_token=secret-token&hub.challenge=12345');
        $response->assertOk();
        $this->assertSame('12345', $response->getContent());

        $this->get('/api/wa/webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1')
            ->assertForbidden();
    }

    public function test_webhook_routes_message_to_account_by_phone_number_id(): void
    {
        $shop = Shop::factory()->create();
        $account = $this->makeAccount($shop);

        $this->postJson('/api/wa/webhook', $this->webhookPayload('pn_123'))->assertOk();

        $contact = WaContact::where('wa_account_id', $account->id)->first();
        $this->assertNotNull($contact);
        $this->assertSame('971501112222', $contact->wa_number);
        $this->assertSame('Ali', $contact->name);
        $this->assertSame(1, $contact->unread_count);
        $this->assertSame('Hello', $contact->last_message_preview);
        $this->assertSame(1, $contact->messages()->count());
    }

    public function test_webhook_ignores_unknown_phone_number_id_and_dedupes_retries(): void
    {
        $shop = Shop::factory()->create();
        $account = $this->makeAccount($shop);

        $this->postJson('/api/wa/webhook', $this->webhookPayload('pn_unknown'))->assertOk();
        $this->assertSame(0, WaContact::count());

        $this->postJson('/api/wa/webhook', $this->webhookPayload('pn_123'))->assertOk();
        $this->postJson('/api/wa/webhook', $this->webhookPayload('pn_123'))->assertOk(); // Meta retry
        $this->assertSame(1, $account->contacts()->first()->messages()->count());
    }

    public function test_account_status_and_save(): void
    {
        $shop = Shop::factory()->create();
        $this->authed($shop);

        $this->getJson('/api/shop/wa/account')->assertOk()->assertJson(['connected' => false]);

        // minimal connect: just the phone number id — token falls back to the shared one
        $this->postJson('/api/shop/wa/account', [
            'phone_number' => '+971500000001',
            'phone_number_id' => 'pn_555',
        ])->assertOk()->assertJson([
            'connected' => true,
            'phone_number_id' => 'pn_555',
            'token_preview' => 'shared',
        ]);

        // own token override
        $this->postJson('/api/shop/wa/account', [
            'phone_number_id' => 'pn_555',
            'token' => 'tok-xyz-9876',
        ])->assertOk();

        $response = $this->getJson('/api/shop/wa/account')->assertOk();
        $response->assertJson(['connected' => true]);
        $this->assertStringContainsString('9876', $response->json('token_preview'));
        $this->assertNull($response->json('token'));

        // update without token keeps existing token
        $this->postJson('/api/shop/wa/account', [
            'phone_number_id' => 'pn_555',
            'phone_number' => '+971500000002',
        ])->assertOk();
        $this->assertSame('tok-xyz-9876', WaAccount::where('shop_id', $shop->id)->first()->token);
    }

    public function test_send_uses_shared_default_token_when_account_has_none(): void
    {
        config(['services.whatsapp.default_token' => 'shared-token-1234']);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out9']]], 200),
        ]);

        $shop = Shop::factory()->create();
        $account = WaAccount::create([
            'shop_id' => $shop->id,
            'phone_number_id' => 'pn_shared',
            'token' => null,
        ]);
        $contact = WaContact::create([
            'wa_account_id' => $account->id,
            'wa_number' => '971501112222',
        ]);

        $this->authed($shop);
        $this->postJson("/api/shop/wa/contacts/{$contact->id}/messages", ['text' => 'hi'])
            ->assertCreated();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer shared-token-1234'));
    }

    public function test_phone_number_id_cannot_be_claimed_by_two_shops(): void
    {
        $shopA = Shop::factory()->create();
        $this->makeAccount($shopA, 'pn_dup');

        $shopB = Shop::factory()->create();
        $this->authed($shopB);

        $this->postJson('/api/shop/wa/account', [
            'phone_number_id' => 'pn_dup',
            'token' => 'tok',
        ])->assertStatus(422);
    }

    public function test_contacts_and_messages_are_scoped_per_shop(): void
    {
        $shopA = Shop::factory()->create();
        $accountA = $this->makeAccount($shopA, 'pn_a');
        $this->postJson('/api/wa/webhook', $this->webhookPayload('pn_a', '971501112222', 'Hi A', 'wamid.a'));

        $shopB = Shop::factory()->create();
        $this->makeAccount($shopB, 'pn_b');
        $this->postJson('/api/wa/webhook', $this->webhookPayload('pn_b', '971503334444', 'Hi B', 'wamid.b'));

        $this->authed($shopA);
        $list = $this->getJson('/api/shop/wa/contacts')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('971501112222', $list[0]['wa_number']);

        // shop A cannot read shop B's thread
        $contactB = WaContact::where('wa_number', '971503334444')->first();
        $this->getJson("/api/shop/wa/contacts/{$contactB->id}/messages")->assertNotFound();
        $this->postJson("/api/shop/wa/contacts/{$contactB->id}/messages", ['text' => 'hack'])->assertNotFound();
    }

    public function test_send_message_calls_graph_api_and_stores_out_message(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out1']]], 200),
        ]);

        $shop = Shop::factory()->create();
        $account = $this->makeAccount($shop);
        $contact = WaContact::create([
            'wa_account_id' => $account->id,
            'wa_number' => '971501112222',
            'name' => 'Ali',
        ]);

        $this->authed($shop);

        $this->postJson("/api/shop/wa/contacts/{$contact->id}/messages", ['text' => 'Salam!'])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'out')
            ->assertJsonPath('data.body', 'Salam!');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/pn_123/messages')
                && $request['text']['body'] === 'Salam!'
                && $request->hasHeader('Authorization', 'Bearer test-token-abcd');
        });

        $contact->refresh();
        $this->assertSame('out', $contact->last_message_direction);
        $this->assertSame('Salam!', $contact->last_message_preview);
    }

    public function test_send_surfaces_graph_error_as_422(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Token expired']], 401),
        ]);

        $shop = Shop::factory()->create();
        $account = $this->makeAccount($shop);
        $contact = WaContact::create([
            'wa_account_id' => $account->id,
            'wa_number' => '971501112222',
        ]);

        $this->authed($shop);
        $this->postJson("/api/shop/wa/contacts/{$contact->id}/messages", ['text' => 'x'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'WhatsApp send failed: Token expired']);
        $this->assertSame(0, $contact->messages()->count());
    }

    public function test_mark_read_clears_unread(): void
    {
        $shop = Shop::factory()->create();
        $account = $this->makeAccount($shop);
        $this->postJson('/api/wa/webhook', $this->webhookPayload('pn_123'));

        $contact = $account->contacts()->first();
        $this->assertSame(1, $contact->unread_count);

        $this->authed($shop);
        $this->postJson("/api/shop/wa/contacts/{$contact->id}/read")->assertOk();
        $this->assertSame(0, $contact->fresh()->unread_count);
    }

    public function test_messages_since_id_polling(): void
    {
        $shop = Shop::factory()->create();
        $account = $this->makeAccount($shop);
        $this->postJson('/api/wa/webhook', $this->webhookPayload('pn_123', '971501112222', 'one', 'wamid.1'));
        $this->postJson('/api/wa/webhook', $this->webhookPayload('pn_123', '971501112222', 'two', 'wamid.2'));

        $contact = $account->contacts()->first();
        $this->authed($shop);

        $all = $this->getJson("/api/shop/wa/contacts/{$contact->id}/messages")->json('data');
        $this->assertCount(2, $all);

        $since = $this->getJson("/api/shop/wa/contacts/{$contact->id}/messages?since_id={$all[0]['id']}")->json('data');
        $this->assertCount(1, $since);
        $this->assertSame('two', $since[0]['body']);
    }

    public function test_relay_out_records_bot_reply(): void
    {
        config(['services.whatsapp.relay_secret' => 'relay-secret']);

        $shop = Shop::factory()->create();
        $account = $this->makeAccount($shop);

        // wrong/missing secret rejected
        $this->postJson('/api/wa/relay-out', [
            'phone_number_id' => 'pn_123', 'to' => '971501112222', 'text' => 'hi',
        ])->assertForbidden();

        $headers = ['X-Relay-Secret' => 'relay-secret'];

        $this->postJson('/api/wa/relay-out', [
            'phone_number_id' => 'pn_123',
            'to' => '971501112222',
            'text' => 'Auto reply from bot',
            'wa_message_id' => 'wamid.bot1',
        ], $headers)->assertOk()->assertJson(['status' => 'ok']);

        $contact = $account->contacts()->where('wa_number', '971501112222')->first();
        $this->assertNotNull($contact);
        $this->assertSame('out', $contact->last_message_direction);
        $this->assertSame('Auto reply from bot', $contact->last_message_preview);
        $this->assertSame(0, $contact->unread_count);

        // duplicate wa_message_id ignored
        $this->postJson('/api/wa/relay-out', [
            'phone_number_id' => 'pn_123',
            'to' => '971501112222',
            'text' => 'Auto reply from bot',
            'wa_message_id' => 'wamid.bot1',
        ], $headers)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->assertSame(1, $contact->messages()->count());

        // unknown phone_number_id ignored quietly
        $this->postJson('/api/wa/relay-out', [
            'phone_number_id' => 'pn_other', 'to' => '97150', 'text' => 'x',
        ], $headers)->assertOk()->assertJson(['status' => 'ignored']);
    }

    public function test_relay_out_stores_bot_voice_reply_audio(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        config(['services.whatsapp.relay_secret' => 'relay-secret']);
        Http::fake([
            'graph.facebook.com/*/media_out1' => Http::response([
                'url' => 'https://lookaside.fbsbx.com/whatsapp/media_out1',
                'mime_type' => 'audio/ogg',
            ], 200),
            'lookaside.fbsbx.com/*' => Http::response('OGGDATA', 200, ['Content-Type' => 'audio/ogg']),
        ]);

        $shop = Shop::factory()->create();
        $account = $this->makeAccount($shop);

        $this->postJson('/api/wa/relay-out', [
            'phone_number_id' => 'pn_123',
            'to' => '971501112222',
            'text' => '🔊 Salam! Booking confirmed.',
            'wa_message_id' => 'wamid.voiceout1',
            'type' => 'audio',
            'media_id' => 'media_out1',
        ], ['X-Relay-Secret' => 'relay-secret'])->assertOk()->assertJson(['status' => 'ok']);

        $message = WaMessage::where('wa_message_id', 'wamid.voiceout1')->first();
        $this->assertSame('audio', $message->type);
        $this->assertSame("wa-media/{$account->id}/media_out1.ogg", $message->media_path);
        $this->assertNotEmpty($message->media_url);
    }

    public function test_relay_out_disabled_when_secret_unset(): void
    {
        config(['services.whatsapp.relay_secret' => null]);
        $this->postJson('/api/wa/relay-out', [
            'phone_number_id' => 'pn_123', 'to' => '97150', 'text' => 'x',
        ], ['X-Relay-Secret' => ''])->assertForbidden();
    }

    public function test_persona_lookup_distinguishes_customers_from_leads(): void
    {
        config(['services.whatsapp.relay_secret' => 'relay-secret']);

        $shop = Shop::factory()->create();
        $this->makeAccount($shop);
        \App\Models\ShopCustomer::create([
            'shop_id' => $shop->id,
            'name' => 'Ali',
            'whatsapp' => '+971 50 111 2222',
            'whatsapp_normalized' => '971501112222',
        ]);

        $headers = ['X-Relay-Secret' => 'relay-secret'];

        // no secret → forbidden
        $this->getJson('/api/wa/persona?phone_number_id=pn_123&number=971501112222')
            ->assertForbidden();

        // known customer (matched on last 9 digits even with different prefix)
        // → includes the shop's locked category so the bot can speak in role
        $shop->update(['category_id' => 9]);
        $this->getJson('/api/wa/persona?phone_number_id=pn_123&number=00971501112222', $headers)
            ->assertOk()->assertJson([
                'persona' => 'customer',
                'shop_name' => $shop->name,
                'category' => 'Salon',
            ]);

        // unknown sender → lead
        $this->getJson('/api/wa/persona?phone_number_id=pn_123&number=971509998888', $headers)
            ->assertOk()->assertJson(['persona' => 'lead']);

        // unknown phone_number_id → lead
        $this->getJson('/api/wa/persona?phone_number_id=pn_nope&number=971501112222', $headers)
            ->assertOk()->assertJson(['persona' => 'lead']);
    }

    public function test_audio_message_is_downloaded_and_exposed_with_media_url(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        config(['services.whatsapp.default_token' => 'shared-tok']);
        Http::fake([
            'graph.facebook.com/*/media_99' => Http::response([
                'url' => 'https://lookaside.fbsbx.com/whatsapp/media_99',
                'mime_type' => 'audio/ogg; codecs=opus',
            ], 200),
            'lookaside.fbsbx.com/*' => Http::response('OGGDATA', 200, ['Content-Type' => 'audio/ogg']),
        ]);

        $shop = Shop::factory()->create();
        $account = $this->makeAccount($shop);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => 'pn_123'],
                        'contacts' => [['wa_id' => '971501112222', 'profile' => ['name' => 'Ali']]],
                        'messages' => [[
                            'from' => '971501112222',
                            'id' => 'wamid.audio1',
                            'type' => 'audio',
                            'audio' => ['id' => 'media_99', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/api/wa/webhook', $payload)->assertOk();

        $message = \App\Models\WaMessage::where('wa_message_id', 'wamid.audio1')->first();
        $this->assertNotNull($message);
        $this->assertSame('audio', $message->type);
        $this->assertSame('media_99', $message->media_id);
        $this->assertSame("wa-media/{$account->id}/media_99.ogg", $message->media_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($message->media_path);

        // thread API exposes a playable URL
        $this->authed($shop);
        $contact = $account->contacts()->first();
        $list = $this->getJson("/api/shop/wa/contacts/{$contact->id}/messages")->json('data');
        $this->assertNotEmpty($list[0]['media_url']);
    }

    public function test_relay_transcript_updates_message_body(): void
    {
        config(['services.whatsapp.relay_secret' => 'relay-secret']);

        $shop = Shop::factory()->create();
        $account = $this->makeAccount($shop);
        $contact = WaContact::create(['wa_account_id' => $account->id, 'wa_number' => '971501112222']);
        $contact->recordMessage('in', '[audio message]', 'audio', 'wamid.audio2');

        $this->postJson('/api/wa/relay-transcript', [
            'wa_message_id' => 'wamid.audio2',
            'transcript' => 'I want to book a haircut tomorrow',
        ], ['X-Relay-Secret' => 'relay-secret'])->assertOk()->assertJson(['status' => 'ok']);

        $this->assertSame(
            '🎤 I want to book a haircut tomorrow',
            \App\Models\WaMessage::where('wa_message_id', 'wamid.audio2')->first()->body
        );
        $this->assertSame('🎤 I want to book a haircut tomorrow', $contact->fresh()->last_message_preview);

        // unknown message ignored quietly; missing secret rejected
        $this->postJson('/api/wa/relay-transcript', [
            'wa_message_id' => 'wamid.nope', 'transcript' => 'x',
        ], ['X-Relay-Secret' => 'relay-secret'])->assertOk()->assertJson(['status' => 'ignored']);
        $this->postJson('/api/wa/relay-transcript', [
            'wa_message_id' => 'wamid.audio2', 'transcript' => 'x',
        ])->assertForbidden();
    }

    public function test_chat_endpoints_require_auth(): void
    {
        $this->getJson('/api/shop/wa/account')->assertUnauthorized();
        $this->getJson('/api/shop/wa/contacts')->assertUnauthorized();
    }
}
