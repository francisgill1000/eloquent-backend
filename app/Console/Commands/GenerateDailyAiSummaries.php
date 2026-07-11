<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GeneratesShopSummaries;
use App\Services\Reports\AiInsightsWriter;
use Illuminate\Console\Command;

/**
 * Pre-generates each active shop's rolling-30-day AI summary overnight (window =
 * 30 days ending yesterday) so the morning load is instant. Covers shops with
 * bookings OR Business Hunt activity in the window; the writer's per-product gate
 * skips still-too-quiet shops. Tenant-scoped; one failure never stops the rest.
 */
class GenerateDailyAiSummaries extends Command
{
    use GeneratesShopSummaries;

    protected $signature = 'ai:daily-summaries {--shop= : Limit to a single shop id (for testing)}';

    protected $description = 'Pre-generate active shops\' rolling-30-day AI summaries for the 30 days ending yesterday';

    public function handle(AiInsightsWriter $writer): int
    {
        $to   = now()->subDay()->endOfDay();
        $from = $to->copy()->subDays(29)->startOfDay();

        $shopIds = $this->option('shop')
            ? [(int) $this->option('shop')]
            : $this->activeShopIds($from->toDateString());

        $r = $this->runFor($writer, $shopIds, $from, $to, 'rolling30');

        $this->info("AI daily summaries: {$r['ok']} generated, {$r['skipped']} skipped (low data), {$r['failed']} failed.");

        return self::SUCCESS;
    }
}
