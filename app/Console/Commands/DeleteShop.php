<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Shop;

class DeleteShop extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shop:delete {id : The ID of the shop to delete}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a shop by its ID';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $id = $this->argument('id');

        $shop = Shop::find($id);

        if (! $shop) {
            $this->error("Shop with ID {$id} not found.");
            return 1;
        }

        try {
            $shop->delete();
        } catch (\Exception $e) {
            $this->error('Failed to delete shop: ' . $e->getMessage());
            return 1;
        }

        $this->info("Shop with ID {$id} deleted.");

        return 0;
    }
}
