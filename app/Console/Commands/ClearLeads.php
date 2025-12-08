<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearLeads extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'leads:clear {--force : Skip confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Clear all leads and related lead activities safely';

    public function handle()
    {
        if (!$this->option('force')) {
            if (!$this->confirm('This will delete ALL leads and lead activities. Continue?')) {
                $this->warn('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        DB::transaction(function () {
            DB::table('lead_activities')->delete();
            DB::table('leads')->delete();
        });

        $this->info('✅ All leads and lead activities have been cleared successfully.');

        return Command::SUCCESS;
    }
}
