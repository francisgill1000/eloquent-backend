<?php

namespace App\Http\Controllers;

use App\Models\CreditPack;
use App\Models\CreditPurchase;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Shop;
use App\Services\Credits\Exceptions\InsufficientCredits;
use App\Services\Credits\HuntCreditService;
use App\Services\Leads\AdLibraryService;
use App\Services\Leads\LeadImporter;
use App\Services\Leads\LeadSearchService;
use App\Services\Leads\OutreachWriter;
use App\Services\Leads\SearchInterpreter;
use App\Services\Ziina;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        private HuntCreditService $credits,
        private LeadImporter $importer,
    ) {
    }

    /**
     * GET /shop/leads/credits
     * The shop's current Business Hunt credit balance plus the active packs, for
     * the balance chip and the low-balance top-up prompt.
     */
    public function credits(Request $request)
    {
        $shop = $this->shop($request);

        // Lazy tidy-up: prod has no cron, so opportunistically expire this shop's
        // abandoned checkouts (>24h pending) on page load. No-op when there are
        // none; never affects paid rows, credits, or money.
        CreditPurchase::expireStale($shop->id);

        return response()->json([
            'credits' => $this->credits->balance($shop),
            'can_purchase' => $this->canPurchase($shop),
            // Client uses inline iframe checkout when true, else redirect.
            'embedded_checkout' => (bool) config('services.ziina.hunt_embedded', false),
            'packs' => CreditPack::where('active', true)
                ->orderBy('sort')->orderBy('price_fils')
                ->get(['id', 'name', 'credits', 'price_fils']),
        ]);
    }

    /**
     * POST /shop/leads/purchase {pack_id}
     * Start a Ziina checkout for a credit pack. Records a 'pending' purchase and
     * returns the hosted-page redirect_url; the credits are granted by the Ziina
     * webhook once payment completes (see ZiinaWebhookController). Runs in Ziina
     * TEST mode until real payments are switched on, so no real money moves.
     * Only shops the master flagged (hunt_self_serve) or the master account may
     * do this; everyone else gets 403 and the UI shows the WhatsApp prompt.
     */
    public function purchase(Request $request, Ziina $ziina)
    {
        $shop = $this->shop($request);

        $data = $request->validate(['pack_id' => ['required', 'integer']]);

        if (! $this->canPurchase($shop)) {
            return response()->json(['error' => 'self_serve_disabled'], 403);
        }

        $pack = CreditPack::where('id', $data['pack_id'])->where('active', true)->first();
        if (! $pack) {
            return response()->json(['error' => 'pack_not_found'], 404);
        }

        // Snapshot credits/amount so a later pack edit can't change this order.
        $purchase = CreditPurchase::create([
            'shop_id' => $shop->id,
            'pack_id' => $pack->id,
            'credits' => $pack->credits,
            'amount_fils' => $pack->price_fils,
            'ziina_operation_id' => (string) Str::uuid(),
            'status' => 'pending',
        ]);

        $base = rtrim((string) config('services.ziina.admin_return_base'), '/');
        $return = "{$base}/leads";
        $intent = $ziina->createCreditPackIntent($shop, $pack, [
            'success_url' => "{$return}?pay=success",
            'cancel_url'  => "{$return}?pay=cancel",
            'failure_url' => "{$return}?pay=failed",
        ]);

        $purchase->update(['ziina_intent_id' => $intent['id'] ?? null]);

        return response()->json([
            'redirect_url' => $intent['redirect_url'] ?? null,
            // For inline (iframe) checkout — the client renders this when embedded
            // mode is on; otherwise it redirects to redirect_url.
            'embedded_url' => $intent['embedded_url'] ?? null,
            'intent_id' => $intent['id'] ?? null,
        ]);
    }

    /** May this shop buy packs self-serve? Master always; others per the flag. */
    private function canPurchase(Shop $shop): bool
    {
        return (bool) $shop->is_master || (bool) $shop->hunt_self_serve;
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
     * free; a live call spends one Business Hunt credit, or returns 429
     * ('insufficient_credits') when the balance is empty.
     */
    public function search(Request $request, SearchInterpreter $interpreter)
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
        ]);

        $shop = $this->shop($request);

        // AI turns whatever the user typed (a keyword or a plain-language goal
        // like "customers for my salon") into a real business-type search. On
        // any AI failure, fall back to searching the raw text literally.
        $keyword = $data['category'];
        $area = $data['area'] ?? null;
        try {
            $interpreted = $interpreter->interpret($shop, $keyword, $area);
            $keyword = $interpreted['keyword'];
            $area = $interpreted['area'];
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $result = $this->search->search($shop, $keyword, $area);
        } catch (InsufficientCredits $e) {
            // 429 (not 402): 402 is reserved for the Lens subscription paywall,
            // which the admin SPA's axios interceptor redirects to /subscribe. A
            // Hunt top-up must stay on the page, so it uses Too Many Requests.
            return response()->json([
                'error' => 'insufficient_credits',
                'credits' => $e->balance,
            ], 429);
        }

        return response()->json([
            'data' => $result['results'],
            'meta' => [
                'from_cache' => $result['from_cache'],
                'credits' => $result['credits'],
                'searched_for' => $keyword,
            ],
        ]);
    }

    /**
     * POST /shop/leads/ad-search
     * Background enrichment for a unified search: scrape businesses running ads
     * and append them to the fast (listings) results already on screen. This is
     * always paired with a prior GET /shop/leads/search, which is the single
     * billable point — so this endpoint does NOT charge the monthly allowance
     * and is not quota-gated here (the paired listings search already enforced
     * the limit before it ran). Cache hits are free + instant. Returns a run id
     * the client polls.
     */
    public function adSearchStart(Request $request)
    {
        $this->shop($request);

        $data = $request->validate([
            'category' => ['required', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
            'fresh' => ['nullable', 'boolean'],
        ]);

        if (! $this->adLibrary->configured()) {
            return response()->json(['error' => 'ad_source_unconfigured'], 503);
        }

        // Cache-first: a repeat search is served free + instant (no re-scrape,
        // no Apify cost). `fresh=true` bypasses the cache and forces a scrape.
        if (! $request->boolean('fresh')) {
            $cached = $this->adLibrary->cachedResults($data['category']);
            if ($cached !== null) {
                return response()->json([
                    'cached' => true,
                    'data' => $cached['results'],
                    'cached_at' => $cached['fetched_at'],
                ]);
            }
        }

        try {
            $runId = $this->adLibrary->start($data['category'], $data['area'] ?? null);
        } catch (\Throwable) {
            return response()->json(['error' => 'ad_search_failed'], 502);
        }

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

        $out = $this->importer->import($shop, $data['leads']);

        // `created` = rows actually inserted (re-saving an existing lead dedupes),
        // so the client can bump the funnel count accurately.
        return response()->json(['data' => $out['saved'], 'created' => $out['created']], 201);
    }

    /**
     * GET /shop/leads/{lead}
     * A single lead with its activity history (newest first) for the detail page.
     */
    public function show(Request $request, Lead $lead)
    {
        $shop = $this->shop($request);
        abort_unless($lead->shop_id === $shop->id, 404);

        $lead->setRelation('shop', $shop);
        $lead->append(['whatsapp_opening_url', 'whatsapp_followup_url']);

        $activities = $lead->activities()
            ->orderByDesc('id')
            ->get(['id', 'type', 'payload', 'created_at']);

        return response()->json([
            'data' => $lead,
            'activities' => $activities,
        ]);
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
     * POST /shop/leads/{lead}/followup
     * Record a follow-up nudge: logs a `contacted` activity and bumps
     * last_contacted_at. Does not change the funnel status.
     */
    public function logFollowup(Request $request, Lead $lead)
    {
        $shop = $this->shop($request);
        abort_unless($lead->shop_id === $shop->id, 404);

        $lead->last_contacted_at = now();
        $lead->save();

        $lead->activities()->create([
            'type' => LeadActivity::TYPE_CONTACTED,
            'payload' => ['channel' => 'whatsapp', 'kind' => 'followup'],
            'user_id' => current_shop_user()?->id,
        ]);

        return response()->json(['data' => $lead->fresh()]);
    }

    /**
     * POST /shop/leads/{lead}/personalize
     * AI-writes ONE ready-to-send message for this specific lead. Does not change
     * status or log activity (that happens when the user opens WhatsApp).
     */
    public function personalize(Request $request, Lead $lead, OutreachWriter $writer)
    {
        $shop = $this->shop($request);
        abort_unless($lead->shop_id === $shop->id, 404);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['opening', 'followup'])],
        ]);

        try {
            $message = $writer->personalizeForLead($shop, $lead, $data['kind']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Could not generate right now. Please try again.'], 502);
        }

        return response()->json(['message' => $message, 'kind' => $data['kind']]);
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
