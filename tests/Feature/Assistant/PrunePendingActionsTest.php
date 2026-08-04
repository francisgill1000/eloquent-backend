<?php
namespace Tests\Feature\Assistant;

use App\Models\AssistantPendingAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retention. A pending row keeps the tool's raw input — customer names and
 * phone numbers for create_customer / create_booking — and nothing used to
 * remove it, so the table grew PII forever. Once a row is well past its
 * 30-minute window it can never be confirmed again, so it is pure residue.
 */
class PrunePendingActionsTest extends TestCase
{
    use RefreshDatabase;

    private function row(\Carbon\CarbonInterface $expiresAt, ?\Carbon\CarbonInterface $resolvedAt = null): AssistantPendingAction
    {
        return AssistantPendingAction::create([
            'shop_id' => 1, 'tool' => 'create_customer',
            'input' => ['name' => 'Sarah', 'phone' => '971500000000'],
            'summary' => 'Add customer "Sarah"', 'changes' => ['customer' => 'new: Sarah'],
            'destructive' => false, 'expires_at' => $expiresAt, 'resolved_at' => $resolvedAt,
        ]);
    }

    public function test_it_deletes_rows_expired_more_than_seven_days_ago(): void
    {
        $old = $this->row(now()->subDays(8));
        $oldResolved = $this->row(now()->subDays(30), now()->subDays(30));
        $recent = $this->row(now()->subDay());
        $live = $this->row(now()->addMinutes(30));

        $this->artisan('assistant:prune-pending-actions')->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNull($oldResolved->fresh());
        $this->assertNotNull($recent->fresh());
        $this->assertNotNull($live->fresh());
    }

    public function test_the_retention_window_is_configurable(): void
    {
        $row = $this->row(now()->subDays(2));

        $this->artisan('assistant:prune-pending-actions --days=1')->assertSuccessful();

        $this->assertNull($row->fresh());
    }

    public function test_the_prune_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'assistant:prune-pending-actions'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }
}
