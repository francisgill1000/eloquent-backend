<?php
namespace Tests\Unit;

use App\Services\Assistant\Support\AssistantActions;
use PHPUnit\Framework\TestCase;

class AssistantActionsTest extends TestCase
{
    public function test_fresh_collector_has_no_pending_action(): void
    {
        $this->assertNull((new AssistantActions())->pending());
    }

    public function test_navigate_records_a_directive(): void
    {
        $a = new AssistantActions();
        $a->navigate('/booking/7');
        $this->assertSame(['type' => 'navigate', 'route' => '/booking/7'], $a->pending());
    }

    public function test_last_navigate_wins(): void
    {
        $a = new AssistantActions();
        $a->navigate('/booking/1');
        $a->navigate('/booking/2');
        $this->assertSame('/booking/2', $a->pending()['route']);
    }
}
