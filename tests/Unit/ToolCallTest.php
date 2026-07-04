<?php
namespace Tests\Unit;

use App\Models\Shop;
use App\Services\Assistant\Support\ToolCall;
use PHPUnit\Framework\TestCase;

class ToolCallTest extends TestCase
{
    public function test_get_returns_input_value_or_default(): void
    {
        $call = new ToolCall(new Shop(), null, 'demo', ['price' => 50], true);
        $this->assertSame(50, $call->get('price'));
        $this->assertSame('x', $call->get('missing', 'x'));
        $this->assertTrue($call->confirmed);
        $this->assertSame('demo', $call->tool);
    }
}
