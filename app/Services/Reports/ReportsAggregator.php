<?php

namespace App\Services\Reports;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportsAggregator
{
    /**
     * Revenue & financial summary for a shop over a date range.
     */
    public function revenueSummary(int $shopId, Carbon $from, Carbon $to): array
    {
        $fromStr = $from->toDateString();
        $toStr   = $to->toDateString();

        // Headline KPIs
        $stats = Booking::where('shop_id', $shopId)
            ->whereBetween('date', [$fromStr, $toStr])
            ->selectRaw("
                count(*) as total_bookings,
                sum(case when lower(status) = 'cancelled' then 1 else 0 end) as cancelled_count,
                sum(case when lower(status) = 'completed' then 1 else 0 end) as completed_count,
                sum(case when lower(status) = 'cancelled' then 0 else charges end) as gross_revenue
            ")
            ->first();

        $grossRevenue = (float) ($stats->gross_revenue ?? 0);
        $netCount = ((int) $stats->total_bookings) - ((int) $stats->cancelled_count);
        $avgValue = $netCount > 0 ? $grossRevenue / $netCount : 0;

        // Paid vs unpaid (via booking_invoices)
        $invoiceStats = DB::table('booking_invoices')
            ->join('bookings', 'bookings.id', '=', 'booking_invoices.booking_id')
            ->where('bookings.shop_id', $shopId)
            ->whereBetween('bookings.date', [$fromStr, $toStr])
            ->selectRaw("
                sum(case when booking_invoices.status = 'paid' then booking_invoices.total else 0 end) as paid_total,
                sum(case when booking_invoices.status = 'issued' then booking_invoices.total else 0 end) as issued_total,
                sum(case when booking_invoices.status = 'cancelled' then booking_invoices.total else 0 end) as cancelled_total,
                count(case when booking_invoices.status = 'paid' then 1 end) as paid_count,
                count(case when booking_invoices.status = 'issued' then 1 end) as issued_count
            ")
            ->first();

        // Daily trend
        $dailyTrend = Booking::where('shop_id', $shopId)
            ->whereBetween('date', [$fromStr, $toStr])
            ->whereRaw("lower(status) != 'cancelled'")
            ->selectRaw('date, count(*) as bookings, sum(charges) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date'     => $r->date,
                'bookings' => (int) $r->bookings,
                'revenue'  => (float) $r->revenue,
            ])
            ->values()
            ->all();

        // Top services by revenue (aggregated from JSON)
        $topServices = $this->aggregateServices($shopId, $fromStr, $toStr);
        usort($topServices, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $topServices = array_slice($topServices, 0, 5);

        return [
            'range' => ['from' => $fromStr, 'to' => $toStr],
            'kpis' => [
                'total_bookings'  => (int) $stats->total_bookings,
                'completed'       => (int) $stats->completed_count,
                'cancelled'       => (int) $stats->cancelled_count,
                'gross_revenue'   => $grossRevenue,
                'avg_booking_value' => $avgValue,
            ],
            'invoices' => [
                'paid_total'      => (float) ($invoiceStats->paid_total ?? 0),
                'issued_total'    => (float) ($invoiceStats->issued_total ?? 0),
                'cancelled_total' => (float) ($invoiceStats->cancelled_total ?? 0),
                'paid_count'      => (int) ($invoiceStats->paid_count ?? 0),
                'issued_count'    => (int) ($invoiceStats->issued_count ?? 0),
            ],
            'daily_trend'  => $dailyTrend,
            'top_services' => $topServices,
        ];
    }

    /**
     * Per-staff performance summary.
     */
    public function staffSummary(int $shopId, Carbon $from, Carbon $to): array
    {
        $fromStr = $from->toDateString();
        $toStr   = $to->toDateString();

        $rows = DB::table('bookings')
            ->leftJoin('staff', 'staff.id', '=', 'bookings.staff_id')
            ->where('bookings.shop_id', $shopId)
            ->whereBetween('bookings.date', [$fromStr, $toStr])
            ->whereNotNull('bookings.staff_id')
            ->selectRaw("
                staff.id as staff_id,
                staff.name as staff_name,
                count(*) as total_bookings,
                sum(case when lower(bookings.status) = 'completed' then 1 else 0 end) as completed,
                sum(case when lower(bookings.status) = 'cancelled' then 1 else 0 end) as cancelled,
                sum(case when lower(bookings.status) = 'cancelled' then 0 else bookings.charges end) as revenue
            ")
            ->groupBy('staff.id', 'staff.name')
            ->orderByDesc('revenue')
            ->get();

        // Also include "Unassigned" bucket for queued / no-staff bookings
        $unassigned = Booking::where('shop_id', $shopId)
            ->whereBetween('date', [$fromStr, $toStr])
            ->whereNull('staff_id')
            ->selectRaw('count(*) as c, sum(charges) as r')
            ->first();

        $staff = $rows->map(function ($r) {
            $total = (int) $r->total_bookings;
            $completed = (int) $r->completed;
            $cancelled = (int) $r->cancelled;
            $revenue = (float) $r->revenue;
            $netCount = $total - $cancelled;
            return [
                'staff_id'           => (int) $r->staff_id,
                'staff_name'         => (string) ($r->staff_name ?? 'Unknown'),
                'total_bookings'     => $total,
                'completed'          => $completed,
                'cancelled'          => $cancelled,
                'revenue'            => $revenue,
                'avg_booking_value'  => $netCount > 0 ? $revenue / $netCount : 0,
                'completion_rate'    => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
                'cancellation_rate'  => $total > 0 ? round(($cancelled / $total) * 100, 1) : 0,
            ];
        })->all();

        if ($unassigned && (int) $unassigned->c > 0) {
            $staff[] = [
                'staff_id'           => null,
                'staff_name'         => 'Unassigned / Queued',
                'total_bookings'     => (int) $unassigned->c,
                'completed'          => 0,
                'cancelled'          => 0,
                'revenue'            => (float) $unassigned->r,
                'avg_booking_value'  => 0,
                'completion_rate'    => 0,
                'cancellation_rate'  => 0,
            ];
        }

        return [
            'range' => ['from' => $fromStr, 'to' => $toStr],
            'staff' => $staff,
        ];
    }

    /**
     * Per-service popularity summary (extracted from bookings.services JSON).
     */
    public function servicesSummary(int $shopId, Carbon $from, Carbon $to): array
    {
        $fromStr = $from->toDateString();
        $toStr   = $to->toDateString();

        $services = $this->aggregateServices($shopId, $fromStr, $toStr);
        usort($services, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'range'    => ['from' => $fromStr, 'to' => $toStr],
            'services' => $services,
        ];
    }

    /**
     * Time patterns: bookings by day-of-week × hour band.
     */
    public function timePatternsSummary(int $shopId, Carbon $from, Carbon $to): array
    {
        $fromStr = $from->toDateString();
        $toStr   = $to->toDateString();

        $bookings = Booking::where('shop_id', $shopId)
            ->whereBetween('date', [$fromStr, $toStr])
            ->whereRaw("lower(status) != 'cancelled'")
            ->select('date', 'start_time', 'charges')
            ->get();

        // 7 (Sun-Sat) × 24 hour grid
        $grid = [];
        for ($d = 0; $d < 7; $d++) {
            $grid[$d] = array_fill(0, 24, 0);
        }
        $byDay = array_fill(0, 7, ['count' => 0, 'revenue' => 0.0]);
        $byHour = array_fill(0, 24, ['count' => 0, 'revenue' => 0.0]);

        foreach ($bookings as $b) {
            $dow = (int) Carbon::parse($b->date)->dayOfWeek; // 0=Sun .. 6=Sat
            $start = $b->getRawOriginal('start_time') ?: '00:00:00';
            $hour = (int) substr($start, 0, 2);
            $hour = max(0, min(23, $hour));

            $grid[$dow][$hour]++;
            $byDay[$dow]['count']++;
            $byDay[$dow]['revenue'] += (float) $b->charges;
            $byHour[$hour]['count']++;
            $byHour[$hour]['revenue'] += (float) $b->charges;
        }

        $dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        return [
            'range'      => ['from' => $fromStr, 'to' => $toStr],
            'day_labels' => $dayLabels,
            'grid'       => $grid,
            'by_day'     => $byDay,
            'by_hour'    => $byHour,
        ];
    }

    /**
     * Retention & quality insights: status breakdown + no-show/cancellation/
     * completion rates, new-vs-returning customers + repeat rate, and a review
     * rating summary. Tenant-scoped by shop_id.
     */
    public function insightsSummary(int $shopId, Carbon $from, Carbon $to): array
    {
        $fromStr = $from->toDateString();
        $toStr   = $to->toDateString();

        $counts = Booking::where('shop_id', $shopId)
            ->whereBetween('date', [$fromStr, $toStr])
            ->selectRaw("
                sum(case when lower(status) = 'completed' then 1 else 0 end) as completed,
                sum(case when lower(status) = 'cancelled' then 1 else 0 end) as cancelled,
                sum(case when lower(status) = 'no_show'   then 1 else 0 end) as no_show,
                sum(case when lower(status) = 'booked'    then 1 else 0 end) as booked
            ")
            ->first();

        $completed = (int) $counts->completed;
        $cancelled = (int) $counts->cancelled;
        $noShow    = (int) $counts->no_show;
        $booked    = (int) $counts->booked;
        $scheduled = $completed + $cancelled + $noShow + $booked; // excludes queued

        $rate = fn (int $n) => $scheduled > 0 ? round($n / $scheduled * 100, 1) : 0.0;

        // Customers with a booking in range (anonymous walk-ins excluded).
        $customerIds = Booking::where('shop_id', $shopId)
            ->whereBetween('date', [$fromStr, $toStr])
            ->whereNotNull('shop_customer_id')
            ->distinct()
            ->pluck('shop_customer_id')
            ->all();

        $returningIds = empty($customerIds) ? [] : Booking::where('shop_id', $shopId)
            ->whereIn('shop_customer_id', $customerIds)
            ->whereDate('date', '<', $fromStr)
            ->distinct()
            ->pluck('shop_customer_id')
            ->all();

        $totalCustomers = count($customerIds);
        $returning = count($returningIds);
        $new = $totalCustomers - $returning;

        // Review rating summary (rated within the range).
        $reviewStats = DB::table('booking_reviews')
            ->where('shop_id', $shopId)
            ->whereNotNull('rating')
            ->whereBetween('rated_at', [$from, $to])
            ->selectRaw('count(*) as c, avg(rating) as a')
            ->first();

        // Per-day time series (zero-filled) so the report can chart a trend.
        $daily = $this->dailyBreakdown($shopId, $from, $to, $fromStr, $toStr);

        return [
            'range' => ['from' => $fromStr, 'to' => $toStr],
            'bookings' => [
                'scheduled' => $scheduled,
                'booked'    => $booked,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'no_show'   => $noShow,
            ],
            'rates' => [
                'completion'   => $rate($completed),
                'cancellation' => $rate($cancelled),
                'no_show'      => $rate($noShow),
            ],
            'customers' => [
                'total'       => $totalCustomers,
                'returning'   => $returning,
                'new'         => $new,
                'repeat_rate' => $totalCustomers > 0 ? round($returning / $totalCustomers * 100, 1) : 0.0,
            ],
            'reviews' => [
                'count'   => (int) ($reviewStats->c ?? 0),
                'average' => $reviewStats && $reviewStats->c ? round((float) $reviewStats->a, 2) : null,
            ],
            'daily' => $daily,
        ];
    }

    /**
     * Per-day booking outcome counts across [from, to], inclusive and
     * zero-filled so charts get a continuous series. Capped at 366 days to
     * keep the payload bounded; longer ranges are downsampled client-side.
     */
    protected function dailyBreakdown(int $shopId, Carbon $from, Carbon $to, string $fromStr, string $toStr): array
    {
        $rows = Booking::where('shop_id', $shopId)
            ->whereBetween('date', [$fromStr, $toStr])
            ->selectRaw("
                date,
                sum(case when lower(status) = 'completed' then 1 else 0 end) as completed,
                sum(case when lower(status) = 'cancelled' then 1 else 0 end) as cancelled,
                sum(case when lower(status) = 'no_show'   then 1 else 0 end) as no_show,
                sum(case when lower(status) = 'booked'    then 1 else 0 end) as booked
            ")
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->date)->toDateString());

        $daily = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        $guard = 0;

        while ($cursor->lte($end) && $guard < 366) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);
            $completed = (int) ($row->completed ?? 0);
            $cancelled = (int) ($row->cancelled ?? 0);
            $noShow    = (int) ($row->no_show ?? 0);
            $booked    = (int) ($row->booked ?? 0);

            $daily[] = [
                'date'      => $key,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'no_show'   => $noShow,
                'booked'    => $booked,
                'total'     => $completed + $cancelled + $noShow + $booked,
            ];

            $cursor->addDay();
            $guard++;
        }

        return $daily;
    }

    /**
     * Internal: aggregate services across all non-cancelled bookings in range.
     */
    protected function aggregateServices(int $shopId, string $fromStr, string $toStr): array
    {
        $bookings = Booking::where('shop_id', $shopId)
            ->whereBetween('date', [$fromStr, $toStr])
            ->whereRaw("lower(status) != 'cancelled'")
            ->select('id', 'services')
            ->get();

        $agg = [];
        foreach ($bookings as $b) {
            $services = is_array($b->services) ? $b->services : (json_decode($b->services ?? '[]', true) ?: []);
            foreach ($services as $s) {
                $title = $s['title'] ?? $s['name'] ?? 'Unknown';
                $price = (float) ($s['price'] ?? 0);
                if (!isset($agg[$title])) {
                    $agg[$title] = ['title' => $title, 'count' => 0, 'revenue' => 0.0];
                }
                $agg[$title]['count']++;
                $agg[$title]['revenue'] += $price;
            }
        }

        return array_map(function ($s) {
            return [
                'title'   => $s['title'],
                'count'   => (int) $s['count'],
                'revenue' => round((float) $s['revenue'], 2),
                'avg_price' => $s['count'] > 0 ? round($s['revenue'] / $s['count'], 2) : 0,
            ];
        }, array_values($agg));
    }
}
