<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Invoice;
use Carbon\Carbon;

class CheckOverdueInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Example: php artisan invoices:overdue
     *
     * @var string
     */
    protected $signature = 'invoices:overdue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get all invoices that have crossed their due date.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Current date and time
        $today = Carbon::now();

        // Fetch overdue invoices
        $overdueInvoices = Invoice::where('due_date', '<', $today)
            // ->where('paid_at') // optional: skip already paid invoices
            ->where('status', Invoice::STATUS_PENDING)
            ->get();

        if ($overdueInvoices->isEmpty()) {
            $this->info('✅ No overdue invoices found.');
            return;
        }

        $this->info('⚠️ Overdue Invoices:');
        $this->table(
            ['ID', 'Customer', 'Amount', 'Due Date',"Total"],
            $overdueInvoices->map(function ($invoice) {
                return [
                    $invoice->id,
                    $invoice->customer->name ?? 'N/A',
                    number_format($invoice->amount, 2),
                    $invoice->due_date,
                    $invoice->total,
                ];
            })->toArray()
        );

        $this->info("Total Overdue: {$overdueInvoices->count()}");
    }
}
