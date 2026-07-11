<?php

namespace App\Console\Commands\Concerns;

use App\Services\Reports\AiInsightsWriter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait GeneratesShopSummaries
{
    /**
     * Active shops = status 'active' with, in the window, either a booking OR
     * Business Hunt activity (a lead created, or a lead status change).
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

    /**
     * Generate + persist a summary for each shop over [from,to] as $periodType.
     *
     * @param  array<int,int>  $shopIds
     * @return array{ok:int, skipped:int, failed:int}
     */
    protected function runFor(AiInsightsWriter $writer, array $shopIds, Carbon $from, Carbon $to, string $periodType): array
    {
        $ok = $skipped = $failed = 0;
        foreach ($shopIds as $shopId) {
            try {
                $result = $writer->summary($shopId, $from->copy(), $to->copy(), true, $periodType);
                $result['state'] === 'ok' ? $ok++ : $skipped++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('summary generation failed', ['shop_id' => $shopId, 'period' => $periodType, 'error' => $e->getMessage()]);
            }
        }

        return ['ok' => $ok, 'skipped' => $skipped, 'failed' => $failed];
    }
}
