<?php

namespace App\Http\Controllers;

use App\Models\BookingReview;
use Illuminate\Http\Request;

class BookingReviewController extends Controller
{
    /**
     * GET /api/reviews/{token} — public. Context for the customer rating page.
     */
    public function show(string $token)
    {
        $review = BookingReview::with(['shop:id,name', 'booking:id,customer_name'])
            ->where('token', $token)->first();

        if (! $review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        return response()->json([
            'shop_name'     => $review->shop?->name,
            'customer_name' => $review->booking?->customer_name,
            'rated'         => $review->rating !== null,
            'rating'        => $review->rating,
        ]);
    }

    /**
     * POST /api/reviews/{token} — public. Records the rating. Ratings >= 4 return
     * the shop's Google review URL (funnel); lower ratings are kept private.
     */
    public function submit(Request $request, string $token)
    {
        $data = $request->validate([
            'rating'  => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $review = BookingReview::with('shop:id,name,google_review_url')
            ->where('token', $token)->first();

        if (! $review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        $review->update([
            'rating'   => $data['rating'],
            'comment'  => $data['comment'] ?? null,
            'rated_at' => now(),
        ]);

        $googleUrl = $data['rating'] >= 4 ? ($review->shop?->google_review_url ?: null) : null;

        return response()->json([
            'google_review_url' => $googleUrl,
            'message'           => $data['rating'] >= 4
                ? 'Thank you! We would love a public review.'
                : 'Thank you for your feedback — we will use it to improve.',
        ]);
    }

    /**
     * GET /api/shop/reviews?shop_id= — owner view. Returns submitted reviews
     * (including private low ratings) plus a summary. Tenant-scoped to the shop.
     */
    public function index(Request $request)
    {
        // Tenant-scope: a non-master shop can only ever read its own reviews,
        // regardless of the shop_id query param (master may inspect any shop).
        $authed = $request->user();
        $shopId = ($authed instanceof \App\Models\Shop && ! $authed->is_master)
            ? $authed->id
            : (int) $request->query('shop_id');
        abort_if(! $shopId, 400, 'shop_id is required');

        $reviews = BookingReview::where('shop_id', $shopId)
            ->whereNotNull('rating')
            ->with('booking:id,customer_name,date')
            ->orderByDesc('rated_at')
            ->get()
            ->map(fn ($r) => [
                'id'            => $r->id,
                'rating'        => $r->rating,
                'comment'       => $r->comment,
                'customer_name' => $r->booking?->customer_name,
                'date'          => $r->booking?->date,
                'rated_at'      => $r->rated_at,
            ]);

        $count = $reviews->count();
        $distribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $distribution[$i] = $reviews->where('rating', $i)->count();
        }

        return response()->json([
            'data'    => $reviews->values(),
            'summary' => [
                'count'        => $count,
                'average'      => $count ? round($reviews->avg('rating'), 2) : null,
                'distribution' => $distribution,
            ],
        ]);
    }
}
