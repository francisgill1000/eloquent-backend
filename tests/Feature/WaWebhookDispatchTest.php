<?php

namespace Tests\Feature;

use App\Jobs\ProcessWaReply;
use App\Models\Shop;
use App\Models\WaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WaWebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $phoneNumberId = 'pn_hook', string $msgId = 'wamid.HOOK1'): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'contacts' => [['wa_id' => '971555000111', 'profile' => ['name' => 'Aisha']]],
                        'messages' => [[
                            'id' => $msgId,
                            'from' => '971555000111',
                            'type' => 'text',
                            'text' => ['body' => 'hello, prices?'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function makeAccount(): WaAccount
    {
        $shop = Shop::factory()->create();

        return WaAccount::create([
            'shop_id' => $shop->id,
            'phone_number' => '+971500000007',
            'phone_number_id' => 'pn_hook',
            'waba_id' => 'waba_hook',
        ]);
    }

    public function test_inbound_message_dispatches_reply_job_once(): void
    {
        Queue::fake();
        $this->makeAccount();

        $this->postJson('/api/wa/webhook', $this->payload())->assertOk();
        // Meta retry of the same message: stored-once, dispatched-once.
        $this->postJson('/api/wa/webhook', $this->payload())->assertOk();

        Queue::assertPushed(ProcessWaReply::class, 1);
    }

    public function test_status_callbacks_dispatch_nothing(): void
    {
        Queue::fake();
        $this->makeAccount();

        $this->postJson('/api/wa/webhook', [
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => 'pn_hook'],
                'statuses' => [['id' => 'wamid.X', 'status' => 'delivered']],
            ]]]]],
        ])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_rejects_bad_signature_when_app_secret_set(): void
    {
        config(['services.whatsapp.app_secret' => 'meta-secret']);
        Queue::fake();
        $this->makeAccount();

        $body = json_encode($this->payload());

        // Wrong signature → 403, nothing stored or dispatched.
        $this->call('POST', '/api/wa/webhook', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(403);
        Queue::assertNothingPushed();

        // Correct signature → accepted.
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'meta-secret');
        $this->call('POST', '/api/wa/webhook', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();
        Queue::assertPushed(ProcessWaReply::class, 1);
    }

    public function test_no_signature_check_when_app_secret_unset(): void
    {
        config(['services.whatsapp.app_secret' => null]);
        Queue::fake();
        $this->makeAccount();

        $this->postJson('/api/wa/webhook', $this->payload())->assertOk();

        Queue::assertPushed(ProcessWaReply::class, 1);
    }
}
