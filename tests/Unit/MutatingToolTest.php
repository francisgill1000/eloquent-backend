<?php
namespace Tests\Unit;

use App\Models\Shop;
use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ToolCall;
use PHPUnit\Framework\TestCase;

/** Minimal concrete MutatingTool exercising the gate. */
class FakeRenameTool extends MutatingTool
{
    /** @var array<int, string> */
    public array $store = [1 => 'Old'];

    protected function permissions(): array
    {
        return ['rename_thing' => 'staff.manage'];
    }

    public function toolDefs(): array
    {
        return [];
    }

    protected function handle(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $call->get('name') === 'ghost'
                ? $this->notFound('thing')
                : ['id' => 1, 'name' => $this->store[1]],
            describe: fn (array $t) => ["Rename to {$call->get('new')}", ['name' => "{$t['name']} → {$call->get('new')}"]],
            write: function (array $t) use ($call) {
                $this->store[$t['id']] = $call->get('new');
                return ['id' => $t['id']];
            },
        );
    }
}

class MutatingToolTest extends TestCase
{
    private function call(bool $confirmed, array $input = ['name' => 'x', 'new' => 'New']): ToolCall
    {
        return new ToolCall(new Shop(), null, 'rename_thing', $input, $confirmed);
    }

    public function test_unconfirmed_call_returns_preview_and_writes_nothing(): void
    {
        $tool = new FakeRenameTool();
        $out = $tool->run($this->call(confirmed: false));

        $this->assertTrue($out['preview']);
        $this->assertSame('Rename to New', $out['action']);
        $this->assertSame(['name' => 'Old → New'], $out['changes']);
        $this->assertSame('Old', $tool->store[1]); // NOT written
        // The preview MUST explicitly signal that nothing was saved, so the
        // model cannot mistake it for success and fabricate a confirmation.
        $this->assertFalse($out['saved']);
        $this->assertIsString($out['next']);
        $this->assertStringContainsString('confirmed=true', $out['next']);
    }

    public function test_confirmed_call_performs_the_write(): void
    {
        $tool = new FakeRenameTool();
        $out = $tool->run($this->call(confirmed: true));

        $this->assertTrue($out['done']);
        $this->assertTrue($out['saved']); // unambiguous success marker
        $this->assertSame('New', $tool->store[1]); // written
    }

    public function test_resolve_not_found_short_circuits_before_write(): void
    {
        $tool = new FakeRenameTool();
        $out = $tool->run($this->call(confirmed: true, input: ['name' => 'ghost', 'new' => 'New']));

        $this->assertSame('not_found', $out['error']);
        $this->assertSame('Old', $tool->store[1]); // untouched
    }

    public function test_handles_reflects_permission_map(): void
    {
        $tool = new FakeRenameTool();
        $this->assertTrue($tool->handles('rename_thing'));
        $this->assertFalse($tool->handles('delete_universe'));
    }
}
