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

        $query = ShopCustomer::where('shop_id', $shop->id)
            ->select([
                'shop_customers.id',
                'shop_customers.name',
                'shop_customers.whatsapp',
                'shop_customers.whatsapp_normalized',
                DB::raw('(select count(*) from bookings where bookings.shop_customer_id = shop_customers.id) as bookings_count'),
                DB::raw("(select coalesce(sum(case when lower(status) != 'cancelled' then charges else 0 end), 0) from bookings where bookings.shop_customer_id = shop_customers.id) as total_spent"),
                DB::raw('(select max(date) from bookings where bookings.shop_customer_id = shop_customers.id) as last_visit_date'),
                DB::raw('(select min(date) from bookings where bookings.shop_customer_id = shop_customers.id) as first_visit_date'),
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

        $query->orderByRaw('last_visit_date IS NULL, last_visit_date DESC')
            ->orderBy('shop_customers.id', 'desc');

        return response()->json($query->paginate($perPage));
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
            'bookings_count'  => $bookingsCount,
            'last_visit_date' => $lastVisit,
        ]);
    }
}
