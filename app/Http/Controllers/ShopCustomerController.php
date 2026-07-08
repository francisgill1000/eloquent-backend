<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\ShopCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopCustomerController extends Controller
{
    /**
     * Paginated list of customers for a shop, with aggregates from bookings.
     */
    public function index(Request $request, Shop $shop)
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $search  = trim((string) $request->query('search', ''));

        $bookingsCountSql  = '(select count(*) from bookings where bookings.shop_customer_id = shop_customers.id)';
        $totalSpentSql     = "(select coalesce(sum(case when lower(status) != 'cancelled' then charges else 0 end), 0) from bookings where bookings.shop_customer_id = shop_customers.id)";
        $lastVisitSql      = '(select max(date) from bookings where bookings.shop_customer_id = shop_customers.id)';
        $firstVisitSql     = '(select min(date) from bookings where bookings.shop_customer_id = shop_customers.id)';

        $query = ShopCustomer::where('shop_id', $shop->id)
            ->select([
                'shop_customers.id',
                'shop_customers.name',
                'shop_customers.whatsapp',
                'shop_customers.whatsapp_normalized',
                DB::raw("$bookingsCountSql as bookings_count"),
                DB::raw("$totalSpentSql as total_spent"),
                DB::raw("$lastVisitSql as last_visit_date"),
                DB::raw("$firstVisitSql as first_visit_date"),
            ]);

        if ($search !== '') {
            $needleDigits = preg_replace('/\D+/', '', $search);
            $query->where(function ($q) use ($search, $needleDigits) {
                $q->where('name', 'LIKE', '%' . $search . '%');
                if ($needleDigits !== '') {
                    $q->orWhere('whatsapp_normalized', 'LIKE', '%' . $needleDigits . '%');
                }
            });
        }

        $query->orderByRaw("$lastVisitSql DESC NULLS LAST")
            ->orderBy('shop_customers.id', 'desc');

        return response()->json($query->paginate($perPage));
    }

    /**
     * Customer detail incl. notes, preferences, and a small booking summary.
     */
    public function show(Shop $shop, ShopCustomer $customer)
    {
        abort_unless((int) $customer->shop_id === (int) $shop->id, 404);

        $agg = $customer->bookings()
            ->selectRaw("
                count(*) as total,
                sum(case when lower(status) = 'completed' then 1 else 0 end) as completed,
                sum(case when lower(status) = 'cancelled' then 1 else 0 end) as cancelled,
                sum(case when lower(status) = 'no_show'   then 1 else 0 end) as no_show,
                sum(case when lower(status) = 'booked'    then 1 else 0 end) as upcoming,
                sum(case when lower(status) != 'cancelled' then charges else 0 end) as total_spent,
                min(date) as first_visit,
                max(date) as last_visit
            ")
            ->first();

        $total       = (int) ($agg->total ?? 0);
        $cancelled   = (int) ($agg->cancelled ?? 0);
        $totalSpent  = (float) ($agg->total_spent ?? 0);
        $nonCancelled = $total - $cancelled;

        $recent = $customer->bookings()
            ->orderByDesc('date')->orderByDesc('start_time')
            ->limit(30)
            ->get(['id', 'booking_reference', 'date', 'start_time', 'status', 'charges', 'services'])
            ->map(fn ($b) => [
                'id'         => $b->id,
                'reference'  => $b->booking_reference,
                'date'       => $b->date,
                'start_time' => $b->getRawOriginal('start_time') ? substr((string) $b->getRawOriginal('start_time'), 0, 5) : null,
                'status'     => strtolower((string) $b->getRawOriginal('status')),
                'charges'    => (float) $b->charges,
                'services'   => $b->services,
            ]);

        return response()->json([
            'data' => [
                'id'               => $customer->id,
                'name'             => $customer->name,
                'whatsapp'         => $customer->whatsapp,
                'notes'            => $customer->notes,
                'preferences'      => $customer->preferences,
                'bookings_count'   => $total,
                'completed_count'  => (int) ($agg->completed ?? 0),
                'cancelled_count'  => $cancelled,
                'no_show_count'    => (int) ($agg->no_show ?? 0),
                'upcoming_count'   => (int) ($agg->upcoming ?? 0),
                'total_spent'      => $totalSpent,
                'avg_spent'        => $nonCancelled > 0 ? round($totalSpent / $nonCancelled, 2) : 0.0,
                'first_visit_date' => $agg->first_visit,
                'last_visit_date'  => $agg->last_visit,
                'bookings'         => $recent,
            ],
        ]);
    }

    /**
     * Update a customer's durable notes / preferences (and name). Tenant-scoped.
     */
    public function update(Request $request, Shop $shop, ShopCustomer $customer)
    {
        abort_unless((int) $customer->shop_id === (int) $shop->id, 404);

        $data = $request->validate([
            'name'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes'       => ['sometimes', 'nullable', 'string', 'max:5000'],
            'preferences' => ['sometimes', 'nullable', 'array'],
        ]);

        $customer->update($data);

        return response()->json(['data' => $customer->fresh()]);
    }

    /**
     * Look up an existing shop customer by WhatsApp number for the booking modal.
     */
    public function lookup(Request $request, Shop $shop)
    {
        $request->validate([
            'whatsapp' => ['required', 'string', 'min:4', 'max:32'],
        ]);

        $normalized = ShopCustomer::normalize($request->whatsapp);
        if (strlen($normalized) < 7) {
            return response()->json(['found' => false]);
        }

        $tail = strlen($normalized) > 9 ? substr($normalized, -9) : $normalized;

        $customer = ShopCustomer::where('shop_id', $shop->id)
            ->where('whatsapp_normalized', 'LIKE', '%' . $tail)
            ->first();

        if (!$customer) {
            return response()->json(['found' => false]);
        }

        $bookingsCount = $customer->bookings()->count();
        $lastVisit = $customer->bookings()->max('date');

        return response()->json([
            'found'           => true,
            'id'              => $customer->id,
            'name'            => $customer->name,
            'whatsapp'        => $customer->whatsapp,
            'notes'           => $customer->notes,
            'preferences'     => $customer->preferences,
            'bookings_count'  => $bookingsCount,
            'last_visit_date' => $lastVisit,
        ]);
    }
}
