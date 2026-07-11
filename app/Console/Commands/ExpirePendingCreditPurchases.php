<?php

namespace App\Console\Commands;

use App\Models\CreditPurchase;
use Illuminate\Console\Command;

/**
 * Tidy-up: mark abandoned Business Hunt checkouts (pending purchases older than
 * --hours) as failed. Purely cosmetic — pending rows never granted credits or
 * took money, so this only keeps reporting clean. Paid rows are never touched.
 */
class ExpirePendingCreditPurchases extends Command
{
    protected $signature = 'hunt:expire-pending-purchases {--hours=24}';

    protected $description = 'Mark abandoned pending Hunt credit purchases (older than --hours) as failed';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $expired = CreditPurchase::expireStale(null, $hours);

        $this->info("Expired {$expired} abandoned pending purchase(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
