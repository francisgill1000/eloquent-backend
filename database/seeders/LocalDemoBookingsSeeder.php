<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Local-dev only: seeds one booking for every date of the CURRENT month for
 * shop #1, with date-appropriate statuses so the dashboard / filters have
 * realistic data to show:
 *   - past dates     -> mostly "completed", every 4th "cancelled"
 *   - today          -> "booked"
 *   - future dates   -> alternating "booked" / "queued"
 *
 *   php artisan db:seed --class=LocalDemoBookingsSeeder
 *
 * Re-running wipes this shop's bookings for the current month and rebuilds them.
 */
class LocalDemoBookingsSeeder extends Seeder
{
    public function run(): void
    {
        $shopId = 1;

        $services = DB::table('catalogs')->where('shop_id', $shopId)
            ->orderBy('id')->get(['id', 'title', 'price']);
        if ($services->isEmpty()) {
            $this->command->warn('No catalog items for shop 1 — run LocalLaundryShopSeeder first.');
            return;
        }

        $staffIds = DB::table('staff')->where('shop_id', $shopId)->pluck('id')->all();
        $staffIds = $staffIds ?: [null];

        $customers = [
            ['Aisha Khan', '0551112233'],
            ['Omar Farooq', '0552223344'],
            ['Priya Nair', '0553334455'],
            ['James Wright', '0554445566'],
            ['Lina Haddad', '0555556677'],
            ['Francis Gill', '0554501483'],
            ['Sara Ali', '0556667788'],
        ];

        $times = ['09:00', '10:30', '12:00', '14:00', '16:30', '18:30'];

        $now        = Carbon::now();
        $today      = $now->copy()->startOfDay();
        $monthStart = $now->copy()->startOfMonth();
        $daysInMonth = $now->daysInMonth;

        // Wipe this shop's bookings for the current month, then rebuild.
        $monthPrefix = $now->format('Y-m'); // e.g. 2026-06
        $killIds = DB::table('bookings')->where('shop_id', $shopId)
            ->where('date', 'like', $monthPrefix . '%')->pluck('id');
        if ($killIds->isNotEmpty()) {
            DB::table('booking_invoices')->whereIn('booking_id', $killIds)->delete();
            DB::table('bookings')->whereIn('id', $killIds)->delete();
        }

        $rows = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = $monthStart->copy()->day($d);
            $i = $d - 1; // 0-based rotation index

            $svc   = $services[$i % $services->count()];
            $start = $times[$i % count($times)];
            $end   = Carbon::createFromFormat('H:i', $start)->addMinutes(30)->format('H:i');
            [$custName, $custPhone] = $customers[$i % count($customers)];

            if ($date->lt($today)) {
                $status = ($d % 4 === 0) ? 'cancelled' : 'completed';
            } elseif ($date->eq($today)) {
                $status = 'booked';
            } else {
                $status = ($d % 2 === 0) ? 'queued' : 'booked';
            }

            $rows[] = [
                'shop_id'           => $shopId,
                'date'              => $date->format('Y-m-d'),
                'start_time'        => $start,
                'end_time'          => $end,
                'status'            => $status,
                'charges'           => $svc->price,
                'discount_amount'   => 0,
                'services'          => json_encode([[
                    'id'    => $svc->id,
                    'title' => $svc->title,
                    'price' => number_format((float) $svc->price, 2, '.', ''),
                ]]),
                'booking_reference' => 'BK' . str_pad((string) $d, 5, '0', STR_PAD_LEFT),
                'customer_name'     => $custName,
                'customer_whatsapp' => $custPhone,
                'staff_id'          => $staffIds[$i % count($staffIds)],
                'created_at'        => $date->copy()->setTime(8, 0),
                'updated_at'        => $now,
            ];
        }

        DB::table('bookings')->insert($rows);

        $byStatus = collect($rows)->countBy('status')->map(fn ($c, $s) => "$s:$c")->values()->implode(', ');
        $this->command->info("Seeded {$daysInMonth} bookings for {$monthPrefix} ({$byStatus})");
    }
}
