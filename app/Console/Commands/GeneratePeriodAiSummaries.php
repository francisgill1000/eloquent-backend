<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GeneratesShopSummaries;
use App\Services\Reports\AiInsightsWriter;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Pre-generates weekly (last complete Mon–Sun) or monthly (last complete calendar
 * month) AI summaries for active shops, so those history views load instantly.
 */
class GeneratePeriodAiSummaries extends Command
{
    use GeneratesShopSummaries;

    protected $signature = 'ai:period-summaries {--period=week : week|month} {--shop= : Limit to a single shop id}';

    protected $description = 'Pre-generate active shops\' weekly or monthly AI summaries for the last complete period';

    public function handle(AiInsightsWriter $writer): int
    {
        $period = $this->option('period');
        if (! in_array($period, ['week', 'month'], true)) {
            $this->error("Unknown --period '{$period}' (use week or month).");

            return self::INVALID;
        }

        if ($period === 'week') {
            $from = now()->subWeek()->startOfWeek(Carbon::MONDAY);
            $to   = now()->subWeek()->endOfWeek(Carbon::SUNDAY);
        } else {
            $from = now()->subMonthNoOverflow()->startOfMonth();
            $to   = now()->subMonthNoOverflow()->endOfMonth();
        }

        $shopIds = $this->option('shop')
            ? [(int) $this->option('shop')]
            : $this->activeShopIds($from->toDateString());

        $r = $this->runFor($writer, $shopIds, $from, $to, $period);

        $this->info("AI {$period} summaries: {$r['ok']} generated, {$r['skipped']} skipped (low data), {$r['failed']} failed.");

        return self::SUCCESS;
    }
}
