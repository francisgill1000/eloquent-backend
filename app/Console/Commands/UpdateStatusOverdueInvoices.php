<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UpdateStatusOverdueInvoices extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'invoices:update-overdue-status';

    /**
     * The console command description.
     */
    protected $description = 'Update status of invoices that have crossed their due date.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $today = Carbon::now();

        // Update all overdue invoices in one query
        $updatedCount = Invoice::where('due_date', '<', $today)
            ->where('status', Invoice::STATUS_PENDING)
            ->update(['status' => Invoice::STATUS_OVERDUE]);

        if ($updatedCount === 0) {
            $this->info('✅ No overdue invoices found.');
        } else {
            $this->info("✅ $updatedCount invoices updated to OVERDUE successfully.");
        }
    }
}
