<?php

namespace Tests\Feature;

use App\Jobs\ProcessWaReply;
use App\Models\Shop;
use App\Models\WaAccount;
use App\Models\WaContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessWaReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.anthropic.key' => 'sk-test',
            'services.anthropic.model' => 'claude-haiku-4-5',
            'services.whatsapp.graph_version' => 'v25.0',
            'services.whatsapp.default_token' => 'system-token',
            'services.openai.key' => null,      // voice off by default
            'services.webpush.public_key' => null, // push off in tests
        ]);
    }

    private function tenantContact(?string $persona = null): WaContact
    {
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9, 'persona' => $persona]);
        $account = WaAccount::create([
            'shop_id' => $shop->id,
            'phone_number' => '+971500000005',
            'phone_number_id' => 'pn_tenant',
            'waba_id' => 'waba_j',
        ]);

        return WaContact::create(['wa_account_id' => $account->id, 'wa_number' => '971555000111', 'name' => 'Aisha']);
    }

    private function fakeClaudeAndGraph(string $replyText = 'Sure! We are open 9 to 5 😊'): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => $replyText]],
            ]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.REPLY1']]]),
        ]);
    }

    public function test_replies_to_tenant_text_with_claude_and_records_outbound(): void
    {
        $this->fakeClaudeAndGraph();
        $contact = $this->tenantContact();
        $inbound = $contact->recordMessage('in', 'what are your timings?', 'text', 'wamid.IN1');

        dispatch_sync(new ProcessWaReply($inbound->id));

        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertNotNull($out);
        $this->assertSame('Sure! We are open 9 to 5 😊', $out->body);
        $this->assertSame('wamid.REPLY1', $out->wa_message_id);
        // Claude got the tenant provider prompt (no custom persona set)
        // along with the booking tools every shop reply now carries.
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'anthropic')) {
                return false;
            }
            return str_contains($request['system'][0]['text'], 'Glow Salon, a salon business')
                && in_array('check_availability', array_column($request['tools'] ?? [], 'name'), true);
        });
    }

    public function test_bare_greeting_gets_canned_welcome_without_claude(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.W1']]])]);
        $contact = $this->tenantContact();
        $inbound = $contact->recordMessage('in', 'hiii', 'text', 'wamid.IN2');

        dispatch_sync(new ProcessWaReply($inbound->id));

        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertStringContainsString('Welcome to Glow Salon', $out->body);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'anthropic'));
    }

    public function test_reactions_and_emoji_only_texts_get_no_reply(): void
    {
        Http::fake();
        $contact = $this->tenantContact();
        $r1 = $contact->recordMessage('in', '[reaction]', 'reaction', 'wamid.IN3');
        $r2 = $contact->recordMessage('in', '👍🙏', 'text', 'wamid.IN4');

        dispatch_sync(new ProcessWaReply($r1->id));
        dispatch_sync(new ProcessWaReply($r2->id));

        $this->assertSame(0, $contact->messages()->where('direction', 'out')->count());
        Http::assertNothingSent();
    }

    public function test_non_text_media_gets_polite_fallback(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.F1']]])]);
        $contact = $this->tenantContact();
        $inbound = $contact->recordMessage('in', '[image message]', 'image', 'wamid.IN5');

        dispatch_sync(new ProcessWaReply($inbound->id));

        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertStringContainsString("couldn't open that", $out->body);
    }

    public function test_does_not_reply_twice_when_thread_already_answered(): void
    {
        Http::fake();
        $contact = $this->tenantContact();
        $inbound = $contact->recordMessage('in', 'hello, prices?', 'text', 'wamid.IN7');
        $contact->recordMessage('out', 'Already answered manually', 'text', 'wamid.MANUAL');

        dispatch_sync(new ProcessWaReply($inbound->id));

        Http::assertNothingSent();
        $this->assertSame(1, $contact->messages()->where('direction', 'out')->count());
    }

    public function test_claude_failure_is_quiet_no_outbound(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response('overloaded', 529)]);
        $contact = $this->tenantContact();
        $inbound = $contact->recordMessage('in', 'hello, prices?', 'text', 'wamid.IN8');

        dispatch_sync(new ProcessWaReply($inbound->id)); // must not throw

        $this->assertSame(0, $contact->messages()->where('direction', 'out')->count());
    }

    public function test_staff_reply_pauses_ai_and_next_customer_message_gets_no_reply(): void
    {
        Http::fake();
        $contact = $this->tenantContact();

        // A human staff member (Jennifer) steps in → the AI auto-pauses.
        $contact->recordMessage('out', 'Hi, this is Jennifer — I’ll take it from here.', 'text', 'wamid.STAFF1', 'sent', [], 'staff');
        $this->assertFalse($contact->fresh()->ai_enabled, 'a staff reply must pause the AI for this thread');

        // The customer messages again afterwards. The AI must stay silent.
        $inbound = $contact->recordMessage('in', 'great, what time can I come in?', 'text', 'wamid.INLATER');

        dispatch_sync(new ProcessWaReply($inbound->id));

        // Nothing left the system and no AI message was recorded — only the one
        // human staff reply remains on the outbound side.
        Http::assertNothingSent();
        $this->assertSame(1, $contact->messages()->where('direction', 'out')->count());
        $this->assertNull($contact->messages()->where('sender_type', 'ai')->first());
    }

    public function test_voice_note_is_transcribed_and_answered_with_voice(): void
    {
        config(['services.openai.key' => 'sk-oai']);
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'how much is a haircut']),
            'api.anthropic.com/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => 'A haircut is 50 AED 😊']]]),
            'api.openai.com/v1/audio/speech' => Http::response('OGGBYTES'),
            'graph.facebook.com/v25.0/pn_tenant/media' => Http::response(['id' => 'media_voice_1']),
            'graph.facebook.com/v25.0/pn_tenant/messages' => Http::response(['messages' => [['id' => 'wamid.V1']]]),
            'graph.facebook.com/v25.0/media_in_1' => Http::response(['url' => 'https://lookaside.test/audio', 'mime_type' => 'audio/ogg']),
            'lookaside.test/audio' => Http::response('INAUDIO'),
        ]);
        $contact = $this->tenantContact();
        $inbound = $contact->recordMessage('in', '[audio message]', 'audio', 'wamid.IN9', null, ['media_id' => 'media_in_1', 'media_mime' => 'audio/ogg']);

        dispatch_sync(new ProcessWaReply($inbound->id));

        $this->assertSame('🎤 how much is a haircut', $inbound->fresh()->body);
        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertSame('🔊 A haircut is 50 AED 😊', $out->body);
        $this->assertSame('audio', $out->type);
        $this->assertSame('media_voice_1', $out->media_id);
    }
}
