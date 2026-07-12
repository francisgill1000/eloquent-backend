<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * Surfaces leads that are due for follow-up today (next_followup_at <= now and
 * still in an active outreach state). v1 logs the per-shop counts; the frontend
 * reads the same set live via GET /shop/leads?followups=due. Push delivery can
 * be layered on later using the existing web-push infra.
 */
class DueFollowups extends Command
{
    protected $signature = 'leads:due-followups';

    protected $description = 'Report leads whose follow-up is due today (status sent|followup|replied)';

    public function handle(): int
    {
        $due = Lead::query()
            ->whereNotNull('next_followup_at')
            ->where('next_followup_at', '<=', now())
            ->whereIn('status', ['sent', 'followup', 'replied'])
            ->get(['id', 'shop_id', 'name', 'next_followup_at', 'status']);

        if ($due->isEmpty()) {
            $this->info('No follow-ups due today.');
            return self::SUCCESS;
        }

        $byShop = $due->groupBy('shop_id');
        foreach ($byShop as $shopId => $leads) {
            $this->line("Shop {$shopId}: {$leads->count()} follow-up(s) due.");
        }

        $this->info("Total due: {$due->count()} across {$byShop->count()} shop(s).");

        return self::SUCCESS;
    }
}
