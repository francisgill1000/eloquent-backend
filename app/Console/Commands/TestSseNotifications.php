<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Notify;

class TestSseNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send test SSE notifications to Node.js server';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Notify::push(108, 'test', "New Booking Created", [
            'booking_id' => 123,
            'customer_name' => 'John Doe',
            'service' => 'Haircut',
        ]);
    }
}
