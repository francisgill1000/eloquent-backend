<?php

namespace App\Services\Reports;

use App\Models\Booking;
use App\Models\Lead;
use App\Support\Rbac;
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
     * Won-deal value for a shop — lifetime when no dates are given, or a period
     * (attributed by deal_won_at) when [from,to] is passed. Only leads whose
     * CURRENT status is 'won' count (a reversed win no longer does). For
     * recurring, deal_amount is the monthly price; total = amount × term.
     */
    /**
     * The agent whose leads the caller is limited to, or null when they see the
     * whole shop.
     *
     * `huntSummary`, `huntByAgent` and `wonValueTotals` read through the raw
     * query builder for portability, so the Lead model's AssignedLeadScope does
     * NOT apply — they must call this and filter explicitly, or an agent would
     * read the whole shop's pipeline and revenue.
     *
     * `huntDaily` and `huntAttention` read through Eloquent instead, so the
     * global scope narrows them to the acting agent automatically — they do NOT
     * call this.
     */
    private function agentLeadFilter(): ?int
    {
        $user = current_shop_user();

        return Rbac::seesAllLeads($user) ? null : $user?->id;
    }

    /**
     * What a won deal is worth in total: a one-off is its amount, a recurring
     * deal is amount × term. Returns null when the row cannot be valued — no
     * amount, or a recurring deal with no term — so every caller skips such
     * rows the same way.
     *
     * Params are mixed because callers reach this both through Eloquent and
     * through the raw query builder, which hands back numeric strings on pgsql.
     */
    private function dealTotal(mixed $amount, mixed $type, mixed $term): ?float
    {
        $amount = (float) ($amount ?? 0);
        if ($amount <= 0) {
            return null;
        }

        if ($type === 'recurring') {
            $term = (int) ($term ?? 0);

            return $term > 0 ? $amount * $term : null;
        }

        return $amount;
    }

    public function wonValueTotals(int $shopId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $agent = $this->agentLeadFilter();
        $q = DB::table('leads')->where('shop_id', $shopId)->where('status', 'won')
            ->when($agent !== null, fn ($b) => $b->where('assigned_to_id', $agent));
        if ($from !== null && $to !== null) {
            $q->whereNotNull('deal_won_at')->whereBetween('deal_won_at', [$from, $to]);
        }
        $rows = $q->get(['deal_amount', 'deal_type', 'deal_term_months']);

        $wonValue = 0.0; $recurring = 0.0; $oneOff = 0.0; $mrr = 0.0; $count = 0;
        foreach ($rows as $d) {
            $total = $this->dealTotal($d->deal_amount, $d->deal_type, $d->deal_term_months);
            if ($total === null) {
                continue;
            }
            if ($d->deal_type === 'recurring') {
                $recurring += $total;
                $mrr += (float) $d->deal_amount;
            } else {
                $oneOff += $total;
            }
            $wonValue += $total;
            $count++;
        }

        return [
            'won_value'           => round($wonValue, 2),
            'won_value_recurring' => round($recurring, 2),
            'won_value_one_off'   => round($oneOff, 2),
            'mrr_won'             => round($mrr, 2),
            'won_count'           => $count,
        ];
    }

    /**
     * Business Hunt pipeline metrics for a shop over a date range. Tenant-scoped
     * via leads.shop_id (lead_activities has no shop_id — joined through leads).
     * `pipeline`/`total_leads` are a CURRENT snapshot; the rest are period-bound.
     */
    public function huntSummary(int $shopId, Carbon $from, Carbon $to): array
    {
        $statuses = Lead::STATUSES;
        $agent = $this->agentLeadFilter();

        // Current pipeline snapshot (not date-bounded), zero-filled.
        $pipeline = array_fill_keys($statuses, 0);
        foreach (
            DB::table('leads')->where('shop_id', $shopId)
                ->when($agent !== null, fn ($b) => $b->where('assigned_to_id', $agent))
                ->selectRaw('status, count(*) as c')->groupBy('status')
                ->pluck('c', 'status') as $st => $c
        ) {
            if (array_key_exists($st, $pipeline)) {
                $pipeline[$st] = (int) $c;
            }
        }

        // Leads created in the period.
        $newLeads = (int) DB::table('leads')->where('shop_id', $shopId)
            ->when($agent !== null, fn ($b) => $b->where('assigned_to_id', $agent))
            ->whereBetween('created_at', [$from, $to])->count();

        // Status changes in the period, aggregated by target status IN PHP
        // (portable across sqlite/pgsql — no JSON SQL).
        $moved = array_fill_keys($statuses, 0);
        $payloads = DB::table('lead_activities')
            ->join('leads', 'leads.id', '=', 'lead_activities.lead_id')
            ->where('leads.shop_id', $shopId)
            ->when($agent !== null, fn ($b) => $b->where('leads.assigned_to_id', $agent))
            ->where('lead_activities.type', 'status_change')
            ->whereBetween('lead_activities.created_at', [$from, $to])
            ->pluck('lead_activities.payload');
        foreach ($payloads as $payload) {
            $data = is_array($payload) ? $payload : json_decode((string) $payload, true);
            $target = is_array($data) ? ($data['to'] ?? null) : null;
            if (is_string($target) && array_key_exists($target, $moved)) {
                $moved[$target]++;
            }
        }

        // Credits spent on live searches (search debits are negative).
        $creditsUsed = (int) abs((int) DB::table('hunt_credit_transactions')
            ->where('shop_id', $shopId)->where('reason', 'search')
            ->whereBetween('created_at', [$from, $to])->sum('amount'));

        // Searches logged in the period.
        $searches = (int) DB::table('lead_search_logs')->where('shop_id', $shopId)
            ->whereBetween('created_at', [$from, $to])->count();

        $wonTotals = $this->wonValueTotals($shopId, $from, $to);

        // Distinct leads first-won in this period — NOT raw funnel-move
        // events (see moved.won, which double-counts a lead won, reversed,
        // then re-won in the same period). Deliberately NOT wonTotals'
        // own won_count either — that one only counts leads with a dollar
        // amount attached, and a win logged without a deal value is still
        // a real win the owner should see counted here.
        $wonInPeriod = (int) DB::table('leads')->where('shop_id', $shopId)
            ->when($agent !== null, fn ($b) => $b->where('assigned_to_id', $agent))
            ->where('status', 'won')
            ->whereNotNull('deal_won_at')
            ->whereBetween('deal_won_at', [$from, $to])
            ->count();

        return [
            'range'        => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'new_leads'    => $newLeads,
            'pipeline'     => $pipeline,
            'total_leads'  => array_sum($pipeline),
            'moved'        => $moved,
            'won'          => $wonInPeriod,
            'won_value'            => $wonTotals['won_value'],
            'won_value_recurring'  => $wonTotals['won_value_recurring'],
            'won_value_one_off'    => $wonTotals['won_value_one_off'],
            'mrr_won'              => $wonTotals['mrr_won'],
            'credits_used' => $creditsUsed,
            'searches'     => $searches,
        ];
    }

    /**
     * Per-agent Hunt performance for the shop, best revenue first. Returns []
     * for a caller who cannot see all leads — an agent's own huntSummary
     * already means "mine", so a one-row leaderboard would be noise.
     *
     * `leads` is a current snapshot of leads held; `won` and `won_value` are
     * period-bound, matching huntSummary's conventions.
     *
     * @return array<int, array{id: int, name: string, leads: int, won: int, won_value: float}>
     */
    public function huntByAgent(int $shopId, Carbon $from, Carbon $to): array
    {
        if ($this->agentLeadFilter() !== null) {
            return [];
        }

        $held = DB::table('leads')->where('shop_id', $shopId)
            ->whereNotNull('assigned_to_id')
            ->selectRaw('assigned_to_id, count(*) as c')
            ->groupBy('assigned_to_id')
            ->pluck('c', 'assigned_to_id');

        $wonRows = DB::table('leads')->where('shop_id', $shopId)
            ->whereNotNull('assigned_to_id')
            ->where('status', 'won')
            ->whereNotNull('deal_won_at')
            ->whereBetween('deal_won_at', [$from, $to])
            ->get(['assigned_to_id', 'deal_amount', 'deal_type', 'deal_term_months']);

        $wonCount = [];
        $wonValue = [];
        foreach ($wonRows as $row) {
            $id = (int) $row->assigned_to_id;
            $wonCount[$id] = ($wonCount[$id] ?? 0) + 1;

            $total = $this->dealTotal($row->deal_amount, $row->deal_type, $row->deal_term_months);
            if ($total === null) {
                continue;
            }
            $wonValue[$id] = ($wonValue[$id] ?? 0) + $total;
        }

        $names = DB::table('shop_users')->where('shop_id', $shopId)->pluck('name', 'id');

        $out = [];
        foreach ($names as $id => $name) {
            $id = (int) $id;
            $leads = (int) ($held[$id] ?? 0);
            $won = (int) ($wonCount[$id] ?? 0);
            if ($leads === 0 && $won === 0) {
                continue; // never handed a lead — keep the table to real agents
            }
            $out[] = [
                'id' => $id,
                'name' => (string) $name,
                'leads' => $leads,
                'won' => $won,
                'won_value' => round((float) ($wonValue[$id] ?? 0), 2),
            ];
        }

        usort($out, fn ($a, $b) => $b['won_value'] <=> $a['won_value']);

        return $out;
    }

    /**
     * Per-day Hunt activity across [from, to], inclusive and zero-filled so the
     * dashboard charts a continuous line. Capped at 366 entries, matching
     * dailyBreakdown().
     *
     * Buckets in PHP rather than SQL: grouping a timestamp by date differs
     * between sqlite (tests) and pgsql (prod), the same reason huntSummary
     * aggregates its status changes in PHP.
     *
     * Reads through Eloquent (Lead::), so AssignedLeadScope narrows it to the
     * acting agent with no explicit filter.
     *
     * @return array<int, array{date: string, leads: int, won: int, won_value: float}>
     */
    public function huntDaily(int $shopId, Carbon $from, Carbon $to): array
    {
        // Bound the queries to whole days so a lead created at 09:00 on the last
        // day is included even when the caller passed a bare (midnight) date.
        $start = $from->copy()->startOfDay();
        $endTs = $to->copy()->endOfDay();

        $created = Lead::where('shop_id', $shopId)
            ->whereBetween('created_at', [$start, $endTs])
            ->pluck('created_at');

        $wonRows = Lead::where('shop_id', $shopId)
            ->where('status', 'won')
            ->whereNotNull('deal_won_at')
            ->whereBetween('deal_won_at', [$start, $endTs])
            ->get(['deal_won_at', 'deal_amount', 'deal_type', 'deal_term_months']);

        $leadsBy = [];
        foreach ($created as $at) {
            $key = Carbon::parse($at)->toDateString();
            $leadsBy[$key] = ($leadsBy[$key] ?? 0) + 1;
        }

        $wonBy = [];
        $valueBy = [];
        foreach ($wonRows as $row) {
            $key = Carbon::parse($row->deal_won_at)->toDateString();
            $wonBy[$key] = ($wonBy[$key] ?? 0) + 1;
            $total = $this->dealTotal($row->deal_amount, $row->deal_type, $row->deal_term_months);
            if ($total !== null) {
                $valueBy[$key] = ($valueBy[$key] ?? 0) + $total;
            }
        }

        $out = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        $guard = 0;

        while ($cursor->lte($end) && $guard < 366) {
            $key = $cursor->toDateString();
            $out[] = [
                'date'      => $key,
                'leads'     => (int) ($leadsBy[$key] ?? 0),
                'won'       => (int) ($wonBy[$key] ?? 0),
                'won_value' => round((float) ($valueBy[$key] ?? 0), 2),
            ];
            $cursor->addDay();
            $guard++;
        }

        return $out;
    }

    /**
     * What needs chasing right now. A CURRENT snapshot, deliberately not
     * date-filtered: an overdue follow-up is overdue no matter which period the
     * dashboard happens to be showing.
     *
     * Reads through Eloquent, which buys two things: AssignedLeadScope narrows
     * it to the acting agent, and the bucket definitions are the very Lead
     * scopes LeadController::index filters on — so a chip's number always
     * matches the list it opens.
     *
     * @return array{followups_overdue: int, followups_today: int, stale: int, unassigned: int}
     */
    public function huntAttention(int $shopId): array
    {
        $base = fn () => Lead::forShop($shopId);

        return [
            'followups_overdue' => $base()->followupOverdue()->count(),
            'followups_today'   => $base()->followupToday()->count(),
            'stale'             => $base()->stale()->count(),
            // No manager special-case: AssignedLeadScope rewrites an agent's
            // query to assigned_to_id = <them>, so this is 0 for them.
            'unassigned'        => $base()->whereNull('assigned_to_id')->count(),
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
