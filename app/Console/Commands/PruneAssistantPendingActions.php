<?php

namespace App\Console\Commands;

use App\Models\AssistantPendingAction;
use Illuminate\Console\Command;

/**
 * Retention for previewed-but-unwritten assistant changes. A pending row stores
 * the tool's raw input, which for create_customer / create_booking includes a
 * customer's name and phone number. Rows are only confirmable for 30 minutes,
 * so anything expired long ago is dead weight holding PII — delete it.
 * Resolved or not is irrelevant: past expiry nothing can act on the row.
 */
class PruneAssistantPendingActions extends Command
{
    protected $signature = 'assistant:prune-pending-actions {--days=7}';

    protected $description = 'Delete assistant pending actions that expired more than --days ago';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $deleted = AssistantPendingAction::where('expires_at', '<', now()->subDays($days))->delete();

        $this->info("Pruned {$deleted} assistant pending action(s) expired over {$days} day(s) ago.");

        return self::SUCCESS;
    }
}
