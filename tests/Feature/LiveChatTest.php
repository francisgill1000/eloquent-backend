<?php

namespace Tests\Feature;

use App\Jobs\ProcessWaReply;
use App\Models\Shop;
use App\Models\User;
use App\Models\WaAccount;
use App\Models\WaContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * In-app Live Chat: the WhatsApp-independent channel. Customer side is keyed
 * by X-Device-Id; owner side shares the bizrezzy WA inbox; replies come from
 * the same ProcessWaReply pipeline without any Graph API call.
 */
class LiveChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.anthropic.key' => 'sk-test',
            'services.anthropic.model' => 'claude-haiku-4-5',
            'services.openai.key' => null,
            'services.webpush.public_key' => null,
        ]);
    }

    private function authedShop(Shop $shop): void
    {
        $token = $shop->createToken('test')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_customer_send_creates_app_contact_and_dispatches_reply_job(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();

        $res = $this->withHeader('X-Device-Id', 'dev-abc')
            ->postJson("/api/chat/shops/{$shop->id}/messages", ['text' => 'Do you have slots today?']);

        $res->assertCreated();
        $contact = WaContact::where('shop_id', $shop->id)->where('device_id', 'dev-abc')->first();
        $this->assertNotNull($contact);
        $this->assertSame('app', $contact->channel);
        $this->assertNull($contact->wa_account_id);
        $this->assertSame(1, $contact->messages()->where('direction', 'in')->count());
        $this->assertSame(1, $contact->unread_count);
        Queue::assertPushed(ProcessWaReply::class, 1);
    }

    public function test_send_without_device_id_is_rejected(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();

        $this->postJson("/api/chat/shops/{$shop->id}/messages", ['text' => 'hello'])
            ->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_poll_returns_only_own_device_thread_and_supports_since_id(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();

        $this->withHeader('X-Device-Id', 'dev-a')
            ->postJson("/api/chat/shops/{$shop->id}/messages", ['text' => 'first']);
        $contact = WaContact::where('device_id', 'dev-a')->firstOrFail();
        $reply = $contact->recordMessage('out', 'Hi! How can I help?', 'text', null, 'sent');

        $mine = $this->withHeader('X-Device-Id', 'dev-a')
            ->getJson("/api/chat/shops/{$shop->id}/messages")
            ->assertOk()->json('data');
        $this->assertCount(2, $mine);

        $fresh = $this->withHeader('X-Device-Id', 'dev-a')
            ->getJson("/api/chat/shops/{$shop->id}/messages?since_id=" . ($reply->id - 1))
            ->assertOk()->json('data');
        $this->assertCount(1, $fresh);
        $this->assertSame('Hi! How can I help?', $fresh[0]['body']);

        $other = $this->withHeader('X-Device-Id', 'dev-b')
            ->getJson("/api/chat/shops/{$shop->id}/messages")
            ->assertOk()->json('data');
        $this->assertSame([], $other);
    }

    public function test_logged_in_customer_name_and_phone_attach_to_contact(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();
        $user = User::factory()->create(['name' => 'Aisha', 'phone' => '+971 55 000 1111']);
        $token = $user->createToken('customer')->plainTextToken;

        $this->withHeaders(['X-Device-Id' => 'dev-u1', 'Authorization' => "Bearer {$token}"])
            ->postJson("/api/chat/shops/{$shop->id}/messages", ['text' => 'hi there, prices?']);

        $contact = WaContact::where('device_id', 'dev-u1')->firstOrFail();
        $this->assertSame('Aisha', $contact->name);
        $this->assertSame('971550001111', $contact->wa_number);
    }

    public function test_owner_inbox_includes_app_threads_even_without_wa_account(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();
        $this->withHeader('X-Device-Id', 'dev-abc')
            ->postJson("/api/chat/shops/{$shop->id}/messages", ['text' => 'hello shop']);

        $this->authedShop($shop);
        $res = $this->getJson('/api/shop/wa/contacts')->assertOk()->json();

        $this->assertFalse($res['connected']);
        $this->assertCount(1, $res['data']);
        $this->assertSame('app', $res['data'][0]['channel']);
    }

    public function test_owner_inbox_mixes_wa_and_app_threads(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();
        $account = WaAccount::create([
            'shop_id' => $shop->id,
            'phone_number_id' => 'pn_mix',
            'token' => 'tok',
        ]);
        WaContact::create(['wa_account_id' => $account->id, 'wa_number' => '971501112222', 'name' => 'Ali']);
        $this->withHeader('X-Device-Id', 'dev-abc')
            ->postJson("/api/chat/shops/{$shop->id}/messages", ['text' => 'hello shop']);

        $this->authedShop($shop);
        $res = $this->getJson('/api/shop/wa/contacts')->assertOk()->json();

        $this->assertTrue($res['connected']);
        $this->assertCount(2, $res['data']);
    }

    public function test_owner_reply_to_app_thread_stores_row_without_graph_call(): void
    {
        Queue::fake();
        Http::fake();
        $shop = Shop::factory()->create();
        $this->withHeader('X-Device-Id', 'dev-abc')
            ->postJson("/api/chat/shops/{$shop->id}/messages", ['text' => 'hello shop']);
        $contact = WaContact::where('device_id', 'dev-abc')->firstOrFail();

        $this->authedShop($shop);
        $this->postJson("/api/shop/wa/contacts/{$contact->id}/messages", ['text' => 'On our way!'])
            ->assertCreated();

        Http::assertNothingSent();
        $this->assertSame('On our way!', $contact->messages()->where('direction', 'out')->value('body'));
    }

    public function test_other_shop_cannot_access_app_thread(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();
        $other = Shop::factory()->create();
        $this->withHeader('X-Device-Id', 'dev-abc')
            ->postJson("/api/chat/shops/{$shop->id}/messages", ['text' => 'hello shop']);
        $contact = WaContact::where('device_id', 'dev-abc')->firstOrFail();

        $this->authedShop($other);
        $this->getJson("/api/shop/wa/contacts/{$contact->id}/messages")->assertNotFound();
        $this->postJson("/api/shop/wa/contacts/{$contact->id}/messages", ['text' => 'hi'])->assertNotFound();
    }

    public function test_ai_replies_to_app_message_with_shop_prompt_and_no_graph_call(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'We are open till 9pm 😊']],
            ]),
        ]);
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9]);
        $contact = WaContact::create([
            'channel' => 'app', 'shop_id' => $shop->id, 'device_id' => 'dev-ai',
        ]);
        $inbound = $contact->recordMessage('in', 'what are your timings?');

        dispatch_sync(new ProcessWaReply($inbound->id));

        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertNotNull($out);
        $this->assertSame('We are open till 9pm 😊', $out->body);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'anthropic')
            && str_contains($request['system'][0]['text'], 'Glow Salon')
            && in_array('create_booking', array_column($request['tools'] ?? [], 'name'), true));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_bare_greeting_on_app_gets_canned_welcome_without_claude(): void
    {
        Http::fake();
        $shop = Shop::factory()->create(['name' => 'Glow Salon']);
        $contact = WaContact::create([
            'channel' => 'app', 'shop_id' => $shop->id, 'device_id' => 'dev-greet',
        ]);
        $inbound = $contact->recordMessage('in', 'hi');

        dispatch_sync(new ProcessWaReply($inbound->id));

        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertStringContainsString('Welcome to Glow Salon', $out->body);
        Http::assertNothingSent();
    }

    public function test_manual_reply_cancels_pending_ai_reply_on_app_thread(): void
    {
        Http::fake();
        $shop = Shop::factory()->create();
        $contact = WaContact::create([
            'channel' => 'app', 'shop_id' => $shop->id, 'device_id' => 'dev-manual',
        ]);
        $inbound = $contact->recordMessage('in', 'price for haircut?');
        $contact->recordMessage('out', 'Answered by the owner already', 'text', null, 'sent');

        dispatch_sync(new ProcessWaReply($inbound->id));

        Http::assertNothingSent();
        $this->assertSame(1, $contact->messages()->where('direction', 'out')->count());
    }
}
