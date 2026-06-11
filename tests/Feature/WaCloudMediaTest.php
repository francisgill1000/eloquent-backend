<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\WaAccount;
use App\Services\WhatsAppCloud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WaCloudMediaTest extends TestCase
{
    use RefreshDatabase;

    private function makeAccount(): WaAccount
    {
        $shop = Shop::factory()->create();

        return WaAccount::create([
            'shop_id' => $shop->id,
            'phone_number' => '+971500000002',
            'phone_number_id' => 'pn_media',
            'waba_id' => 'waba_media',
            'token' => 'shop-token',
        ]);
    }

    public function test_upload_media_returns_media_id(): void
    {
        config(['services.whatsapp.graph_version' => 'v25.0']);
        Http::fake(['graph.facebook.com/v25.0/pn_media/media' => Http::response(['id' => 'media_123'])]);

        $id = (new WhatsAppCloud())->uploadMedia($this->makeAccount(), 'OGGBYTES', 'audio/ogg');

        $this->assertSame('media_123', $id);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer shop-token'));
    }

    public function test_upload_media_throws_without_id(): void
    {
        config(['services.whatsapp.graph_version' => 'v25.0']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'bad']], 400)]);

        $this->expectException(\RuntimeException::class);
        (new WhatsAppCloud())->uploadMedia($this->makeAccount(), 'OGGBYTES', 'audio/ogg');
    }

    public function test_send_voice_posts_audio_message(): void
    {
        config(['services.whatsapp.graph_version' => 'v25.0']);
        Http::fake(['graph.facebook.com/v25.0/pn_media/messages' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

        $result = (new WhatsAppCloud())->sendVoice($this->makeAccount(), '+971 55 500 0111', 'media_123');

        $this->assertSame('wamid.OUT1', $result['messages'][0]['id']);
        Http::assertSent(function ($request) {
            return $request['type'] === 'audio'
                && $request['audio'] === ['id' => 'media_123']
                && $request['to'] === '971555000111'; // digits only
        });
    }
}
