<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\CampaignRecipient;
use App\Models\MarketingCampaign;
use App\Models\PromoCode;
use App\Services\CustomerSegmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MarketingCampaignController extends Controller
{
    public function __construct(private CustomerSegmentService $segments)
    {
    }

    /**
     * GET /shop/marketing/campaigns?shop_id=...
     * Lists campaigns with attributed-bookings stats for ROI display.
     */
    public function index(Request $request)
    {
        $shopId = (int) $request->query('shop_id');
        abort_if(! $shopId, 400, 'shop_id is required');

        $campaigns = MarketingCampaign::where('shop_id', $shopId)
            ->with('promoCode:id,code,label,discount_type,discount_value')
            ->orderByDesc('id')
            ->get()
            ->map(function ($c) {
                $bookings = Booking::where('marketing_campaign_id', $c->id)
                    ->whereRaw("LOWER(status) != 'cancelled'")
                    ->get(['id', 'charges']);
                return [
                    'id'                => $c->id,
                    'name'              => $c->name,
                    'segment'           => $c->segment,
                    'segment_params'    => $c->segment_params,
                    'message_template'  => $c->message_template,
                    'recipients_count'  => $c->recipients_count,
                    'sent_at'           => $c->sent_at,
                    'promo_code'        => $c->promoCode,
                    'bookings_count'    => $bookings->count(),
                    'revenue'           => round($bookings->sum('charges'), 2),
                ];
            });

        // Period-level summary used for the dashboard widget.
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $monthCampaigns = MarketingCampaign::where('shop_id', $shopId)
            ->where('sent_at', '>=', $monthStart)
            ->get();
        $monthBookings = Booking::where('shop_id', $shopId)
            ->whereIn('marketing_campaign_id', $monthCampaigns->pluck('id'))
            ->whereRaw("LOWER(status) != 'cancelled'")
            ->get(['id', 'charges']);
        $summary = [
            'campaigns'        => $monthCampaigns->count(),
            'messages_sent'    => (int) $monthCampaigns->sum('recipients_count'),
            'bookings_count'   => $monthBookings->count(),
            'revenue'          => round($monthBookings->sum('charges'), 2),
        ];

        return response()->json([
            'data'    => $campaigns,
            'summary' => $summary,
        ]);
    }

    /**
     * GET /shop/marketing/segments?shop_id=...&segment=lapsed
     * Preview the resolved recipients for a segment.
     */
    public function previewSegment(Request $request)
    {
        $shopId  = (int) $request->query('shop_id');
        $segment = (string) $request->query('segment', 'all');
        $params  = (array) $request->query('params', []);
        abort_if(! $shopId, 400, 'shop_id is required');

        $recipients = $this->segments->resolve($shopId, $segment, $params);

        return response()->json([
            'segment'    => $segment,
            'count'      => $recipients->count(),
            'recipients' => $recipients->values(),
            'available'  => $this->segments->availableSegments(),
        ]);
    }

    /**
     * POST /shop/marketing/campaigns
     * Records a campaign + its resolved recipients. Returns the recipients so
     * the frontend can open WhatsApp deep links per-recipient (wa.me) or batch.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'shop_id'          => ['required', 'integer'],
            'name'             => ['required', 'string', 'max:120'],
            'segment'          => ['required', 'string'],
            'segment_params'   => ['nullable', 'array'],
            'message_template' => ['required', 'string', 'max:2000'],
            'promo_code_id'    => ['nullable', 'integer'],
        ]);

        // Verify the promo code belongs to this shop, if provided.
        if (! empty($data['promo_code_id'])) {
            $owns = PromoCode::where('id', $data['promo_code_id'])
                ->where('shop_id', $data['shop_id'])
                ->exists();
            abort_unless($owns, 422, 'Promo code does not belong to this shop.');
        }

        $recipients = $this->segments->resolve(
            (int) $data['shop_id'],
            (string) $data['segment'],
            (array) ($data['segment_params'] ?? []),
        );

        abort_if($recipients->isEmpty(), 422, 'No recipients match this segment.');

        $campaign = MarketingCampaign::create([
            'shop_id'          => $data['shop_id'],
            'name'             => $data['name'],
            'segment'          => $data['segment'],
            'segment_params'   => $data['segment_params'] ?? null,
            'message_template' => $data['message_template'],
            'promo_code_id'    => $data['promo_code_id'] ?? null,
            'recipients_count' => $recipients->count(),
            'sent_at'          => now(),
        ]);

        $rows = $recipients->map(fn ($r) => [
            'marketing_campaign_id' => $campaign->id,
            'shop_customer_id'      => $r['shop_customer_id'] ?? null,
            'customer_name'         => $r['name'] ?? null,
            'customer_whatsapp'     => $r['whatsapp'] ?? null,
            'sent_at'               => now(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ])->all();
        CampaignRecipient::insert($rows);

        return response()->json([
            'data' => [
                'id'               => $campaign->id,
                'name'             => $campaign->name,
                'recipients_count' => $campaign->recipients_count,
                'recipients'       => $recipients->values(),
                'message_template' => $campaign->message_template,
                'sent_at'          => $campaign->sent_at,
            ],
        ], 201);
    }

    /**
     * GET /shop/marketing/campaigns/{campaign}
     * Returns recipient rollup for a single campaign (used in detail view).
     */
    public function show(MarketingCampaign $campaign)
    {
        $recipients = $campaign->recipients()
            ->orderByDesc('booked_at')
            ->orderBy('id')
            ->get();
        $bookings = Booking::where('marketing_campaign_id', $campaign->id)
            ->whereRaw("LOWER(status) != 'cancelled'")
            ->get(['id', 'charges']);
        return response()->json([
            'data' => $campaign->load('promoCode'),
            'recipients' => $recipients,
            'stats' => [
                'recipients_count' => $campaign->recipients_count,
                'bookings_count'   => $bookings->count(),
                'revenue'          => round($bookings->sum('charges'), 2),
            ],
        ]);
    }
}
