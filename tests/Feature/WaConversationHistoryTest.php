<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\WaAccount;
use App\Models\WaContact;
use App\Support\Wa\ConversationHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaConversationHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeContact(): WaContact
    {
        $shop = Shop::factory()->create();
        $account = WaAccount::create([
            'shop_id' => $shop->id,
            'phone_number' => '+971500000001',
            'phone_number_id' => 'pn_hist',
            'waba_id' => 'waba_hist',
        ]);

        return WaContact::create(['wa_account_id' => $account->id, 'wa_number' => '971555000111']);
    }

    public function test_maps_directions_to_roles_in_order(): void
    {
        $contact = $this->makeContact();
        $contact->recordMessage('in', 'hello there, prices?');
        $contact->recordMessage('out', 'Haircut is 50 AED 😊');
        $contact->recordMessage('in', 'book me tomorrow');

        $this->assertSame([
            ['role' => 'user', 'content' => 'hello there, prices?'],
            ['role' => 'assistant', 'content' => 'Haircut is 50 AED 😊'],
            ['role' => 'user', 'content' => 'book me tomorrow'],
        ], ConversationHistory::for($contact));
    }

    public function test_strips_voice_prefixes_and_skips_placeholders(): void
    {
        $contact = $this->makeContact();
        $contact->recordMessage('in', '🎤 how much is a haircut', 'audio');
        $contact->recordMessage('out', '🔊 It is 50 AED', 'audio');
        $contact->recordMessage('in', '[image message]', 'image');
        $contact->recordMessage('in', 'ok thanks');

        $this->assertSame([
            ['role' => 'user', 'content' => 'how much is a haircut'],
            ['role' => 'assistant', 'content' => 'It is 50 AED'],
            ['role' => 'user', 'content' => 'ok thanks'],
        ], ConversationHistory::for($contact));
    }

    public function test_merges_consecutive_same_role_turns(): void
    {
        $contact = $this->makeContact();
        $contact->recordMessage('in', 'hi');
        $contact->recordMessage('in', 'anyone there?');

        $this->assertSame([
            ['role' => 'user', 'content' => "hi\nanyone there?"],
        ], ConversationHistory::for($contact));
    }

    public function test_drops_leading_assistant_turns_and_limits(): void
    {
        $contact = $this->makeContact();
        $contact->recordMessage('out', 'Welcome!'); // would lead with assistant
        for ($i = 1; $i <= 12; $i++) {
            $contact->recordMessage('in', "question {$i}");
            $contact->recordMessage('out', "answer {$i}");
        }

        $history = ConversationHistory::for($contact);

        $this->assertLessThanOrEqual(10, count($history));
        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('answer 12', end($history)['content']);
    }
}
