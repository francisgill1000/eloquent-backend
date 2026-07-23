<?php

namespace App\Http\Controllers;

use App\Models\CreditPack;
use App\Models\CreditPurchase;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Services\Credits\Exceptions\InsufficientCredits;
use App\Services\Credits\HuntCreditService;
use App\Services\Leads\AdLibraryService;
use App\Services\Leads\LeadImporter;
use App\Services\Leads\LeadSearchService;
use App\Services\Leads\OutreachWriter;
use App\Services\Leads\SearchInterpreter;
use App\Services\Ziina;
use App\Support\Rbac;
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
        $intent = $ziina->createCreditPackIntent($shop, $pack, $purchase->ziina_operation_id, [
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
     * Guard a route-bound lead: right tenant AND visible to the acting user.
     *
     * The visibility half cannot be left to AssignedLeadScope — Laravel resolves
     * route-model bindings in the `api` group's SubstituteBindings, which runs
     * BEFORE the route-level rbac.context middleware sets the acting ShopUser.
     * At bind time the scope therefore sees a null user and treats the request
     * as owner-equivalent. Without this check an agent could open, re-status or
     * reassign a colleague's lead by guessing its id.
     *
     * 404 rather than 403 — an agent should not learn that the id exists.
     */
    private function guardLead(Lead $lead, Shop $shop): void
    {
        abort_unless($lead->shop_id === $shop->id, 404);
        abort_unless($lead->visibleTo(current_shop_user()), 404);
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
            // The pipeline/list these leads are filed under — required so every
            // saved batch is grouped (e.g. "digital media pipeline").
            'pipeline' => ['required', 'string', 'max:120'],
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

        $out = $this->importer->import($shop, $data['leads'], $data['pipeline']);

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
        $this->guardLead($lead, $shop);

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
        $this->guardLead($lead, $shop);

        $data = $request->validate([
            'status' => ['required', Rule::in(Lead::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
            // Deal value is only meaningful when winning; all optional so the
            // funnel is never blocked on money.
            'deal_amount' => ['nullable', 'numeric', 'min:0'],
            'deal_type' => ['nullable', Rule::in(Lead::DEAL_TYPES)],
            // A recurring deal must carry a term; a one-off must not.
            'deal_term_months' => [
                'nullable',
                Rule::requiredIf(fn () => ($request->input('deal_type') === 'recurring')),
                Rule::in(Lead::DEAL_TERMS),
            ],
        ]);

        $from = $lead->status;
        $lead->status = $data['status'];
        $lead->last_contacted_at = now();

        // Capture / update the deal only on a win. deal_won_at is stamped once
        // (first win) so re-winning a lead keeps its original won date.
        if ($data['status'] === 'won') {
            // A null/absent deal_amount means "win without touching the deal" —
            // applyWonDeal only sets the fields when an amount is given, so a
            // re-win never wipes a prior deal. (Deliberate: differs from the old
            // inline clear-on-explicit-null.)
            $lead->applyWonDeal(
                $data['deal_amount'] ?? null,
                $data['deal_type'] ?? null,
                $data['deal_term_months'] ?? null,
            );
        }

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
        $this->guardLead($lead, $shop);

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
     * PATCH /shop/leads/{lead}/assign {assigned_to_id}
     * Hand one lead to an agent, or pass null to return it to the pool.
     */
    public function assign(Request $request, Lead $lead)
    {
        $shop = $this->shop($request);
        $this->guardLead($lead, $shop);

        $data = $request->validate([
            'assigned_to_id' => ['present', 'nullable', 'integer'],
        ]);

        $lead->assignTo($this->resolveAssignee($shop, $data['assigned_to_id']), current_shop_user());

        return response()->json(['data' => $lead->fresh()?->load('assignedTo:id,name,is_active')]);
    }

    /**
     * POST /shop/leads/assign {ids, assigned_to_id}
     * Bulk hand-out from the pipeline's multi-select. Deliberately runs through
     * the normal visibility scope, so a manager can only assign leads they can
     * already see.
     */
    public function assignBulk(Request $request)
    {
        $shop = $this->shop($request);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'assigned_to_id' => ['present', 'nullable', 'integer'],
        ]);

        $target = $this->resolveAssignee($shop, $data['assigned_to_id']);
        $actor = current_shop_user();

        $leads = Lead::forShop($shop->id)->whereIn('id', $data['ids'])->get();
        foreach ($leads as $lead) {
            $lead->assignTo($target, $actor);
        }

        return response()->json(['assigned' => $leads->count()]);
    }

    /**
     * PATCH /shop/leads/settings {lead_auto_assign}
     * Hunt hand-out behaviour. Lives here rather than under Settings because it
     * is Hunt behaviour, and Settings is a permission surface we keep narrow.
     */
    public function updateSettings(Request $request)
    {
        $shop = $this->shop($request);

        $data = $request->validate([
            'lead_auto_assign' => ['required', 'boolean'],
        ]);

        $shop->lead_auto_assign = $data['lead_auto_assign'];
        $shop->save();

        return response()->json(['lead_auto_assign' => (bool) $shop->lead_auto_assign]);
    }

    /**
     * Resolve an assignee id to an active ShopUser of THIS shop. The shop comes
     * from the token, never the body, so a valid id from another tenant is
     * rejected rather than silently accepted.
     */
    private function resolveAssignee(Shop $shop, ?int $id): ?ShopUser
    {
        if ($id === null) {
            return null;
        }

        $user = ShopUser::where('shop_id', $shop->id)
            ->where('id', $id)
            ->where('is_active', true)
            ->first();

        abort_if($user === null, 422, 'Assignee must be an active user of this shop.');

        return $user;
    }

    /**
     * POST /shop/leads/{lead}/personalize
     * AI-writes ONE ready-to-send message for this specific lead. Does not change
     * status or log activity (that happens when the user opens WhatsApp).
     */
    public function personalize(Request $request, Lead $lead, OutreachWriter $writer)
    {
        $shop = $this->shop($request);
        $this->guardLead($lead, $shop);

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

        $query = Lead::forShop($shop->id)->with('assignedTo:id,name,is_active');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if (($pipeline = $request->query('pipeline')) !== null && $pipeline !== '') {
            $query->where('pipeline', $pipeline);
        }
        if ($term = $request->query('search')) {
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('address', 'like', "%{$term}%")
                    ->orWhere('pipeline', 'like', "%{$term}%");
            });
        }
        if ($request->query('followups') === 'due') {
            $query->whereNotNull('next_followup_at')
                ->where('next_followup_at', '<=', now())
                ->whereIn('status', ['sent', 'followup', 'replied']);
        }

        // Owner filter: 'me' | 'unassigned' | a shop_user id. Independent of the
        // visibility scope — an agent filtering by 'me' just sees what they
        // already see.
        $assigned = $request->query('assigned_to');
        if ($assigned !== null && $assigned !== '') {
            if ($assigned === 'unassigned') {
                $query->whereNull('assigned_to_id');
            } elseif ($assigned === 'me') {
                $me = current_shop_user()?->id;
                // An untagged session has no "me" — return nothing rather than
                // silently falling back to everything.
                $me === null ? $query->whereRaw('1 = 0') : $query->where('assigned_to_id', $me);
            } else {
                $query->where('assigned_to_id', (int) $assigned);
            }
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

        // Distinct pipeline names (non-empty) so the UI can offer a group filter.
        $pipelines = Lead::forShop($shop->id)
            ->whereNotNull('pipeline')
            ->where('pipeline', '!=', '')
            ->distinct()
            ->orderBy('pipeline')
            ->pluck('pipeline')
            ->values();

        // Lifetime value of deals currently held as won (reversed deals excluded
        // because their status is no longer 'won'). Summed via the derived
        // deal_total so it stays consistent with the model.
        $wonValue = Lead::forShop($shop->id)
            ->where('status', 'won')
            ->whereNotNull('deal_amount')
            ->get(['deal_amount', 'deal_type', 'deal_term_months'])
            ->sum(fn (Lead $l) => $l->deal_total ?? 0);

        // The pool the hand-out picker offers. Withheld from users who cannot
        // assign, so the staff list never leaks through the leads endpoint to
        // someone without users.view.
        $assignees = Rbac::userCan(current_shop_user(), 'leads.assign')
            ? ShopUser::where('shop_id', $shop->id)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])
            : collect();

        return response()->json([
            'data' => $leads,
            'funnel' => $funnel,
            'pipelines' => $pipelines,
            'won_value' => round((float) $wonValue, 2),
            'assignees' => $assignees,
            'auto_assign' => (bool) $shop->lead_auto_assign,
        ]);
    }
}
