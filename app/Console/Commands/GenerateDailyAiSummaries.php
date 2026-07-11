<?php

namespace App\Console\Commands;

use App\Services\Reports\AiInsightsWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pre-generates each active shop's AI performance summary overnight for the 30
 * days ending yesterday (today is still incomplete), so the owner sees an
 * instant, already-stored summary in the morning instead of waiting on a live
 * Claude call. Force-refresh writes both the 24h cache and the ai_summaries row.
 *
 * Only shops with bookings or Business Hunt activity in the window are
 * considered; the writer's own per-product gate then skips a paid Claude
 * call for still-too-quiet shops.
 * Tenant-scoped throughout; one shop failing never stops the rest.
 */
class GenerateDailyAiSummaries extends Command
{
    protected $signature = 'ai:daily-summaries {--shop= : Limit to a single shop id (for testing)}';

    protected $description = 'Pre-generate active shops\' AI performance summaries for the 30 days ending yesterday';

    public function handle(AiInsightsWriter $writer): int
    {
        $to   = now()->subDay()->endOfDay();               // yesterday (app tz: Asia/Dubai)
        $from = $to->copy()->subDays(29)->startOfDay();    // 30-day window ending yesterday

        $shopIds = $this->option('shop')
            ? [(int) $this->option('shop')]
            : $this->activeShopIds($from->toDateString());

        $ok = $skipped = $failed = 0;

        foreach ($shopIds as $shopId) {
            try {
                $result = $writer->summary($shopId, $from->copy(), $to->copy(), true);
                $result['state'] === 'ok' ? $ok++ : $skipped++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('ai:daily-summaries failed', ['shop_id' => $shopId, 'error' => $e->getMessage()]);
            }
        }

        $this->info("AI daily summaries: {$ok} generated, {$skipped} skipped (low data), {$failed} failed.");

        return self::SUCCESS;
    }

    /**
     * Active shops = status 'active' with, in the window, either a booking OR
     * Business Hunt activity (a lead created, or a lead status change). Keeps the
     * nightly run off dormant tenants while covering both products.
     *
     * @return array<int, int>
     */
    protected function activeShopIds(string $fromDate): array
    {
        $bookingShops = DB::table('bookings')
            ->join('shops', 'shops.id', '=', 'bookings.shop_id')
            ->where('shops.status', 'active')
            ->where('bookings.date', '>=', $fromDate)
            ->distinct()->pluck('bookings.shop_id');

        $newLeadShops = DB::table('leads')
            ->join('shops', 'shops.id', '=', 'leads.shop_id')
            ->where('shops.status', 'active')
            ->where('leads.created_at', '>=', $fromDate)
            ->distinct()->pluck('leads.shop_id');

        $activityShops = DB::table('lead_activities')
            ->join('leads', 'leads.id', '=', 'lead_activities.lead_id')
            ->join('shops', 'shops.id', '=', 'leads.shop_id')
            ->where('shops.status', 'active')
            ->where('lead_activities.type', 'status_change')
            ->where('lead_activities.created_at', '>=', $fromDate)
            ->distinct()->pluck('leads.shop_id');

        return $bookingShops->merge($newLeadShops)->merge($activityShops)
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
