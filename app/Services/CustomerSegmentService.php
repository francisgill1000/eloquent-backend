<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ShopCustomer;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CustomerSegmentService
{
    public const SEGMENTS = [
        'all'           => 'All customers',
        'lapsed'        => 'Lapsed (no booking in 60+ days)',
        'top_spenders'  => 'Top 20% spenders',
        'recent'        => 'Recent (last 30 days)',
        'birthday'      => 'Birthday this month',
    ];

    /**
     * Resolve a segment name + params into a list of recipients
     * (each item: ['shop_customer_id', 'name', 'whatsapp']).
     */
    public function resolve(int $shopId, string $segment, array $params = []): Collection
    {
        $base = ShopCustomer::where('shop_id', $shopId)
            ->whereNotNull('whatsapp_normalized')
            ->where('whatsapp_normalized', '!=', '');

        switch ($segment) {
            case 'lapsed':
                $cutoff = Carbon::now()->subDays((int) ($params['days'] ?? 60))->toDateString();
                $recentIds = Booking::where('shop_id', $shopId)
                    ->where('date', '>=', $cutoff)
                    ->whereNotNull('shop_customer_id')
                    ->distinct()
                    ->pluck('shop_customer_id');
                $base->whereNotIn('id', $recentIds);
                break;

            case 'recent':
                $cutoff = Carbon::now()->subDays((int) ($params['days'] ?? 30))->toDateString();
                $recentIds = Booking::where('shop_id', $shopId)
                    ->where('date', '>=', $cutoff)
                    ->whereNotNull('shop_customer_id')
                    ->distinct()
                    ->pluck('shop_customer_id');
                $base->whereIn('id', $recentIds);
                break;

            case 'top_spenders':
                $perCustomer = Booking::where('shop_id', $shopId)
                    ->whereRaw("LOWER(status) != 'cancelled'")
                    ->whereNotNull('shop_customer_id')
                    ->selectRaw('shop_customer_id, SUM(charges) as total_spent')
                    ->groupBy('shop_customer_id')
                    ->orderByDesc('total_spent')
                    ->get();
                $top = $perCustomer->take(max(1, (int) ceil($perCustomer->count() * 0.2)));
                $base->whereIn('id', $top->pluck('shop_customer_id'));
                break;

            case 'birthday':
                // Birthday support — only meaningful if a `birthday` column is added later.
                // For now, just return an empty collection so the UI handles it gracefully.
                if (! \Schema::hasColumn('shop_customers', 'birthday')) {
                    return collect();
                }
                $base->whereRaw("strftime('%m', birthday) = ?", [now()->format('m')]);
                break;

            case 'all':
            default:
                // No additional filter
                break;
        }

        return $base
            ->orderBy('name')
            ->get(['id', 'name', 'whatsapp', 'whatsapp_normalized'])
            ->map(fn ($c) => [
                'shop_customer_id' => $c->id,
                'name'             => $c->name,
                'whatsapp'         => $c->whatsapp,
            ]);
    }

    public function availableSegments(): array
    {
        return self::SEGMENTS;
    }
}
