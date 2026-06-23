<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Ai\AssistantAgent;
use App\Services\Ai\AssistantTools;
use App\Services\Wa\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
    }

    /** Queue Anthropic responses in order; each is a full /messages body. */
    private function fakeTurns(array $bodies): void
    {
        $seq = Http::fakeSequence('api.anthropic.com/v1/messages');
        foreach ($bodies as $b) {
            $seq->push($b, 200);
        }
    }

    private function textTurn(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]]];
    }

    private function toolTurn(string $text, string $tool, array $input, string $id = 'tu_1'): array
    {
        return ['content' => array_values(array_filter([
            $text !== '' ? ['type' => 'text', 'text' => $text] : null,
            ['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => (object) $input],
        ]))];
    }

    private function agent(): AssistantAgent
    {
        return new AssistantAgent(new ClaudeClient());
    }

    public function test_plain_text_turn_returns_reply_with_no_action(): void
    {
        $this->fakeTurns([$this->textTurn('Hi! I can help you find services.')]);

        $out = $this->agent()->run('sys', [['role' => 'user', 'content' => 'hello']], new AssistantTools('dev-1'));

        $this->assertSame('Hi! I can help you find services.', $out['reply']);
        $this->assertNull($out['action']);
    }

    public function test_read_tool_then_text_runs_two_turns_and_collects_shops(): void
    {
        Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'category_id' => 1, 'name' => 'Sharp Cuts']);

        $this->fakeTurns([
            $this->toolTurn('', 'search_shops', ['category_id' => 1]),
            $this->textTurn('Here are some barbers 👇'),
        ]);

        $out = $this->agent()->run('sys', [['role' => 'user', 'content' => 'find a barber']], new AssistantTools('dev-1'));

        $this->assertSame('Here are some barbers 👇', $out['reply']);
        $this->assertSame('Sharp Cuts', $out['shops']->first()->name);
    }

    public function test_action_tool_short_circuits_with_navigate_directive(): void
    {
        $this->fakeTurns([$this->toolTurn('Taking you to your bookings!', 'navigate', ['route' => '/bookings'])]);

        $out = $this->agent()->run('sys', [['role' => 'user', 'content' => 'show my bookings page']], new AssistantTools('dev-1'));

        $this->assertSame(['type' => 'navigate', 'route' => '/bookings'], $out['action']);
        $this->assertSame('Taking you to your bookings!', $out['reply']);
    }

    public function test_register_action_carries_collected_fields(): void
    {
        $this->fakeTurns([$this->toolTurn('Great — last step!', 'register', ['name' => 'Aisha', 'phone' => '0501234567'])]);

        $out = $this->agent()->run('sys', [['role' => 'user', 'content' => 'sign me up, I am Aisha 0501234567']], new AssistantTools('dev-1'));

        $this->assertSame('register', $out['action']['type']);
        $this->assertSame(['name' => 'Aisha', 'phone' => '0501234567'], $out['action']['fields']);
    }

    public function test_navigate_to_existing_shop_short_circuits(): void
    {
        $shop = Shop::factory()->create(['status' => Shop::ACTIVE, 'is_master' => false, 'name' => 'Hina Salon']);

        $this->fakeTurns([$this->toolTurn('Opening Hina Salon!', 'navigate', ['route' => "/shop/{$shop->id}"])]);

        $out = $this->agent()->run('sys', [['role' => 'user', 'content' => 'book hina salon']], new AssistantTools('dev-1'));

        $this->assertSame(['type' => 'navigate', 'route' => "/shop/{$shop->id}"], $out['action']);
    }

    public function test_navigate_to_nonexistent_shop_feeds_error_and_model_recovers(): void
    {
        // Model guesses a bad id first; after the error it searches and replies.
        $this->fakeTurns([
            $this->toolTurn('', 'navigate', ['route' => '/shop/9999']),
            $this->textTurn("I couldn't find that shop — try searching by name."),
        ]);

        $out = $this->agent()->run('sys', [['role' => 'user', 'content' => 'book hina salon']], new AssistantTools('dev-1'));

        $this->assertNull($out['action']);
        $this->assertSame("I couldn't find that shop — try searching by name.", $out['reply']);
    }

    public function test_invalid_navigate_route_is_ignored(): void
    {
        $this->fakeTurns([
            $this->toolTurn('', 'navigate', ['route' => '/evil']),
            $this->textTurn('I can take you to your bookings or account.'),
        ]);

        $out = $this->agent()->run('sys', [['role' => 'user', 'content' => 'go somewhere']], new AssistantTools('dev-1'));

        $this->assertNull($out['action']);
        $this->assertSame('I can take you to your bookings or account.', $out['reply']);
    }
}
