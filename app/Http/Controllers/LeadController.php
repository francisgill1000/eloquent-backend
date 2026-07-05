<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Shop;
use App\Services\Leads\AdLibraryService;
use App\Services\Leads\Exceptions\SearchLimitReached;
use App\Services\Leads\LeadSearchService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lead Finder — a UAE prospecting tool (search real businesses → save →
 * WhatsApp/call → track to won). Deliberately NOT a generic CRM: one flat
 * `leads` object, a fixed funnel, action-first. All endpoints are tenant-scoped
 * to the authenticated Shop; the shop id is never taken from the request body.
 */
class LeadController extends Controller
{
    public function __construct(
        private LeadSearchService $search,
        private AdLibraryService $adLibrary,
    ) {
    }

    /** The authenticated tenant. */
    private function shop(Request $request): Shop
    {
        $shop = $request->user();
        abort_unless($shop instanceof Shop, 401, 'Shop authentication required.');
        return $shop;
    }

    /**
     * GET /shop/leads/search?category=&area=
     * Discover businesses via the active source. Does NOT save. Cache hits are
     * free; a live call over the monthly allowance returns 402.
     */
    public function search(Request $request)
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $result = $this->search->search(
                $this->shop($request),
                $data['category'],
                $data['area'] ?? null,
            );
        } catch (SearchLimitReached $e) {
            // 429 (not 402): 402 is reserved for lapsed subscriptions, which the
            // admin SPA's axios interceptor redirects to /subscribe. A quota
            // limit must stay on the page, so it uses Too Many Requests instead.
            return response()->json([
                'error' => 'search_limit_reached',
                'used' => $e->used,
                'limit' => $e->limit,
            ], 429);
        }

        return response()->json([
            'data' => $result['results'],
            'meta' => [
                'from_cache' => $result['from_cache'],
                'used' => $result['used'],
                'limit' => $result['limit'],
                'remaining' => $result['remaining'],
            ],
        ]);
    }

    /**
     * POST /shop/leads/ad-search
     * Start an async "Ad Activity" scrape (businesses running Meta ads). Charges
     * one monthly search up-front (Apify cost is incurred now). Returns a run id
     * the client polls. 429 when the allowance is exhausted.
     */
    public function adSearchStart(Request $request)
    {
        $shop = $this->shop($request);

        $data = $request->validate([
            'category' => ['required', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
        ]);

        if (! $this->adLibrary->configured()) {
            return response()->json(['error' => 'ad_source_unconfigured'], 503);
        }

        // Cache-first: a repeat search is served free + instant (no re-scrape,
        // no Apify cost, no quota spent).
        $cached = $this->adLibrary->cachedResults($data['category']);
        if ($cached !== null) {
            return response()->json(['cached' => true, 'data' => $cached]);
        }

        [$used, $limit] = $this->search->usage($shop);
        if ($used >= $limit) {
            return response()->json(['error' => 'search_limit_reached', 'used' => $used, 'limit' => $limit], 429);
        }

        try {
            $runId = $this->adLibrary->start($data['category'], $data['area'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'ad_search_failed'], 502);
        }

        // Charge the quota now — the scrape is already running (and billable).
        $this->search->recordSearch($shop, $data['category'], $data['area'] ?? null);

        return response()->json(['run_id' => $runId]);
    }

    /**
     * GET /shop/leads/ad-search/{runId}
     * Poll an Ad Activity scrape. Returns {status: running|done|failed, data:[]}.
     */
    public function adSearchPoll(Request $request, string $runId)
    {
        $this->shop($request);

        $result = $this->adLibrary->poll($runId);

        // Cache a finished run under its keyword so the next identical search is
        // a free, instant hit. The client echoes back the category it searched.
        if ($result['status'] === 'done' && ($category = $request->query('category'))) {
            $this->adLibrary->cacheResults($category, $result['results']);
        }

        return response()->json([
            'status' => $result['status'],
            'data' => $result['results'],
        ]);
    }

    /**
     * POST /shop/leads
     * Persist selected search results as leads. Dedupes on (shop_id,
     * external_ref) so re-saving the same business updates rather than clones.
     */
    public function store(Request $request)
    {
        $shop = $this->shop($request);

        $data = $request->validate([
            'leads' => ['required', 'array', 'min:1'],
            'leads.*.name' => ['required', 'string', 'max:255'],
            'leads.*.phone' => ['nullable', 'string', 'max:60'],
            'leads.*.whatsapp' => ['nullable', 'string', 'max:60'],
            'leads.*.website' => ['nullable', 'string', 'max:2048'],
            'leads.*.address' => ['nullable', 'string', 'max:1024'],
            'leads.*.category' => ['nullable', 'string', 'max:120'],
            'leads.*.lat' => ['nullable', 'numeric'],
            'leads.*.lng' => ['nullable', 'numeric'],
            'leads.*.source' => ['nullable', 'string', 'max:60'],
            'leads.*.external_ref' => ['nullable', 'string', 'max:255'],
        ]);

        $saved = [];
        foreach ($data['leads'] as $row) {
            $attrs = [
                'name' => $row['name'],
                'phone' => $row['phone'] ?? null,
                'whatsapp' => $row['whatsapp'] ?? ($row['phone'] ?? null),
                'website' => $row['website'] ?? null,
                'address' => $row['address'] ?? null,
                'category' => $row['category'] ?? null,
                'lat' => $row['lat'] ?? null,
                'lng' => $row['lng'] ?? null,
                'source' => $row['source'] ?? 'manual',
            ];

            // Dedupe on external_ref when present; otherwise always a new lead.
            if (! empty($row['external_ref'])) {
                $lead = Lead::firstOrNew([
                    'shop_id' => $shop->id,
                    'external_ref' => $row['external_ref'],
                ]);
                $lead->fill($attrs);
                if (! $lead->exists) {
                    $lead->status = 'new';
                }
                $lead->save();
            } else {
                $lead = Lead::create($attrs + [
                    'shop_id' => $shop->id,
                    'status' => 'new',
                ]);
            }

            $saved[] = $lead;
        }

        return response()->json(['data' => $saved], 201);
    }

    /**
     * PATCH /shop/leads/{lead}/status
     * Move a lead through the funnel: updates status, logs an activity row, and
     * bumps last_contacted_at.
     */
    public function updateStatus(Request $request, Lead $lead)
    {
        $shop = $this->shop($request);
        abort_unless($lead->shop_id === $shop->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(Lead::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $from = $lead->status;
        $lead->status = $data['status'];
        $lead->last_contacted_at = now();
        $lead->save();

        $lead->activities()->create([
            'type' => LeadActivity::TYPE_STATUS_CHANGE,
            'payload' => array_filter([
                'from' => $from,
                'to' => $data['status'],
                'note' => $data['note'] ?? null,
            ], fn ($v) => $v !== null),
            'user_id' => current_shop_user()?->id,
        ]);

        return response()->json(['data' => $lead->fresh()]);
    }

    /**
     * GET /shop/leads?status=&category=&search=&followups=due
     * The tenant's leads with filters, plus funnel counts per status.
     */
    public function index(Request $request)
    {
        $shop = $this->shop($request);

        $query = Lead::forShop($shop->id);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($term = $request->query('search')) {
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('address', 'like', "%{$term}%");
            });
        }
        if ($request->query('followups') === 'due') {
            $query->whereNotNull('next_followup_at')
                ->where('next_followup_at', '<=', now())
                ->whereIn('status', ['sent', 'replied']);
        }

        $leads = $query->orderByDesc('id')->get();

        // Funnel counts are always the full per-shop picture (unfiltered).
        $funnel = array_fill_keys(Lead::STATUSES, 0);
        foreach (
            Lead::forShop($shop->id)
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status') as $st => $c
        ) {
            $funnel[$st] = (int) $c;
        }

        return response()->json([
            'data' => $leads,
            'funnel' => $funnel,
        ]);
    }
}
