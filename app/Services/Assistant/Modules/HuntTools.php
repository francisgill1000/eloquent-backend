<?php
namespace App\Services\Assistant\Modules;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ResolvesLeads;
use App\Services\Assistant\Support\ToolCall;
use App\Services\Credits\Exceptions\InsufficientCredits;
use App\Services\Credits\HuntCreditService;
use App\Services\Leads\LeadImporter;
use App\Services\Leads\LeadSearchService;
use App\Services\Leads\SearchInterpreter;

/**
 * Owner-assistant Business Hunt MUTATING tools (leads module): run a live
 * (credit-spending) search, save results to the pipeline, and move a lead
 * through the funnel. Confirm-gated via MutatingTool.
 */
class HuntTools extends MutatingTool
{
    use ResolvesLeads;

    public function __construct(
        protected LeadSearchService $search,
        protected SearchInterpreter $interpreter,
        protected LeadImporter $importer,
        protected HuntCreditService $credits,
    ) {}

    public function moduleKey(): ?string
    {
        return 'leads';
    }

    protected function permissions(): array
    {
        // Live search spends a credit → its own permission; the rest are pipeline
        // work (owner and untagged sessions bypass, see Rbac).
        return [
            'search_businesses' => 'leads.search',
            'save_leads' => 'leads.manage',
            'update_lead_status' => 'leads.manage',
            'log_followup' => 'leads.manage',
        ];
    }

    protected function handle(ToolCall $call): array
    {
        return match ($call->tool) {
            'search_businesses' => $this->searchBusinesses($call),
            'save_leads' => $this->saveLeads($call),
            'update_lead_status' => $this->updateStatus($call),
            'log_followup' => $this->logFollowup($call),
            default => ['error' => 'unknown_tool'],
        };
    }

    /** Interpret the raw category+area into a real search term. Never throws. */
    private function interpret(ToolCall $call): array
    {
        $keyword = (string) $call->get('category');
        $area = $call->get('area');
        try {
            $out = $this->interpreter->interpret($call->shop, $keyword, $area);

            return [$out['keyword'], $out['area']];
        } catch (\Throwable $e) {
            report($e);

            return [$keyword, $area];
        }
    }

    /**
     * Live search — spends 1 credit on a cache miss. Hand-rolled (not gate())
     * so an InsufficientCredits result comes back as a plain error, NOT wrapped
     * in applied()'s done=true.
     */
    private function searchBusinesses(ToolCall $call): array
    {
        if (trim((string) $call->get('category')) === '') {
            return ['error' => 'not_found', 'what' => 'missing_category'];
        }

        // Interpret up front so the confirmation preview names the REAL search
        // term (interpret() is cached, so reusing it on confirm is free).
        [$keyword, $area] = $this->interpret($call);

        if (! $call->confirmed) {
            $bal = $this->credits->balance($call->shop);
            $where = $area ? " in {$area}" : '';

            return $this->preview(
                "Search for \"{$keyword}\"{$where}. A live search uses 1 credit — you have {$bal} (a repeat of a recent search is free).",
                ['credits' => "{$bal} → up to " . max(0, $bal - 1)],
            );
        }

        try {
            $result = $this->search->search($call->shop, $keyword, $area);
        } catch (InsufficientCredits $e) {
            return ['error' => 'insufficient_credits', 'credits' => $e->balance];
        }

        $rows = $result['results'];

        return $this->applied([
            'count' => count($rows),
            'from_cache' => $result['from_cache'],
            'credits_left' => $result['credits'],
            'searched_for' => $keyword,
            'area' => $area,
            'sample' => array_slice(array_map(fn ($r) => $r['name'] ?? 'Unknown', $rows), 0, 5),
        ]);
    }

    /**
     * Save the results of the just-run search — a credit-free cache lookup, so
     * this never bills. not_found when there is no matching cached search.
     */
    private function saveLeads(ToolCall $call): array
    {
        if (trim((string) $call->get('category')) === '') {
            return ['error' => 'not_found', 'what' => 'missing_category'];
        }

        [$keyword, $area] = $this->interpret($call);
        $rows = $this->search->cached($keyword, $area);

        return $this->gate(
            $call,
            resolve: fn () => empty($rows) ? $this->notFound('search results') : ['rows' => $rows],
            describe: fn () => [
                'Save all ' . count($rows) . " \"{$keyword}\" businesses" . ($area ? " in {$area}" : '') . ' to your leads',
                ['leads' => count($rows) . ' to add'],
            ],
            write: function () use ($call, $rows) {
                $out = $this->importer->import($call->shop, $rows);

                return ['saved' => count($out['saved']), 'created' => $out['created']];
            },
        );
    }

    private function updateStatus(ToolCall $call): array
    {
        $new = strtolower(trim((string) $call->get('status')));
        if (! in_array($new, Lead::STATUSES, true)) {
            return ['error' => 'invalid_status'];
        }

        // Deal value is only meaningful on a win. Normalise + validate up front.
        $amount = $call->get('deal_amount');
        $amount = ($amount === null || $amount === '') ? null : (float) $amount;
        $type = $call->get('deal_type');
        $term = $call->get('deal_term_months');
        $term = ($term === null || $term === '') ? null : (int) $term;

        if ($new === 'won' && $amount !== null) {
            if ($amount < 0) {
                return ['error' => 'invalid_deal_amount'];
            }
            if ($type !== null && ! in_array($type, Lead::DEAL_TYPES, true)) {
                return ['error' => 'invalid_deal_type'];
            }
            if ($type === 'recurring') {
                if ($term === null) {
                    return ['error' => 'missing_deal_term'];
                }
                if (! in_array($term, Lead::DEAL_TERMS, true)) {
                    return ['error' => 'invalid_deal_term'];
                }
            }
        }

        return $this->gate(
            $call,
            resolve: fn () => $this->resolveLead($call),
            describe: fn ($lead) => [
                $this->describeStatusChange($lead, $new, $amount, $type, $term),
                ['status' => "{$lead->status} → {$new}"],
            ],
            write: function ($lead) use ($new, $amount, $type, $term) {
                $from = $lead->status;
                $lead->status = $new;
                $lead->last_contacted_at = now();
                if ($new === 'won') {
                    $lead->applyWonDeal($amount, $type, $term);
                }
                $lead->save();

                // Mirrors LeadController::updateStatus — status change is not an
                // import, so it does not go through LeadImporter.
                $lead->activities()->create([
                    'type' => LeadActivity::TYPE_STATUS_CHANGE,
                    'payload' => ['from' => $from, 'to' => $new],
                    'user_id' => current_shop_user()?->id,
                ]);

                return ['name' => $lead->name, 'status' => $new, 'deal_total' => $lead->deal_total];
            },
        );
    }

    private function logFollowup(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolveLead($call),
            describe: fn ($lead) => ["Log a follow-up with {$lead->name} (no status change)", ['followup' => 'logged']],
            write: function ($lead) {
                $lead->last_contacted_at = now();
                $lead->save();

                $lead->activities()->create([
                    'type' => LeadActivity::TYPE_CONTACTED,
                    'payload' => ['channel' => 'whatsapp', 'kind' => 'followup'],
                    'user_id' => current_shop_user()?->id,
                ]);

                return ['name' => $lead->name, 'logged' => true];
            },
        );
    }

    /** Confirm-preview line; names the deal when winning with an amount. */
    private function describeStatusChange(Lead $lead, string $new, ?float $amount, ?string $type, ?int $term): string
    {
        $base = "Move {$lead->name} from {$lead->status} to {$new}";
        if ($new !== 'won' || $amount === null) {
            return $base;
        }
        if (($type ?? 'one_off') === 'recurring') {
            $total = $amount * (int) $term;
            return "{$base} — AED {$amount}/month × {$term} = AED {$total} total";
        }
        return "{$base} — AED {$amount} one-off";
    }

    public function toolDefs(): array
    {
        return [
            ['name' => 'search_businesses', 'description' => 'Run a live business search to find leads. Give a category (e.g. "gyms", "hotels") and an optional area. A live search spends 1 Business Hunt credit; a repeat of a recent search is free. Confirm first (call with confirmed:true only after the owner agrees). Does NOT save — use save_leads afterwards.', 'input_schema' => ['type' => 'object', 'properties' => [
                'category' => ['type' => 'string', 'description' => 'Business type to look for, e.g. "gyms in Dubai Marina".'],
                'area' => ['type' => 'string', 'description' => 'Optional UAE area/city.'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['category']]],
            ['name' => 'save_leads', 'description' => 'Save the businesses from the most recent search into the lead pipeline. Pass the same category and area that were just searched. Spends no credit. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'category' => ['type' => 'string'],
                'area' => ['type' => 'string'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['category']]],
            ['name' => 'update_lead_status', 'description' => 'Move a lead through the funnel (new, sent, followup, replied, demo, won, pass). Identify the lead by business name. When moving to "won", you may also capture the deal value: deal_amount (AED), deal_type ("one_off" or "recurring"), and for recurring a deal_term_months of 1, 3, 6, or 12 (deal_amount is the MONTHLY price for recurring). Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'The business/lead name (fuzzy match).'],
                'status' => ['type' => 'string', 'enum' => Lead::STATUSES],
                'deal_amount' => ['type' => 'number', 'description' => 'Deal value in AED when winning. Monthly price if recurring, whole amount if one-off.'],
                'deal_type' => ['type' => 'string', 'enum' => Lead::DEAL_TYPES],
                'deal_term_months' => ['type' => 'integer', 'enum' => Lead::DEAL_TERMS, 'description' => 'Contract length for a recurring deal.'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name', 'status']]],
            ['name' => 'log_followup', 'description' => 'Record that the owner followed up with a lead (a nudge) WITHOUT changing its funnel stage. Use when the owner says they messaged/called a lead again but nothing moved yet. Identify the lead by business name. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'The business/lead name (fuzzy match).'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name']]],
        ];
    }
}
