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
        return [
            'search_businesses' => null,
            'save_leads' => null,
            'update_lead_status' => null,
        ];
    }

    protected function handle(ToolCall $call): array
    {
        return match ($call->tool) {
            'search_businesses' => $this->searchBusinesses($call),
            'save_leads' => $this->saveLeads($call),
            'update_lead_status' => $this->updateStatus($call),
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

        return $this->gate(
            $call,
            resolve: fn () => $this->resolveLead($call),
            describe: fn ($lead) => ["Move {$lead->name} from {$lead->status} to {$new}", ['status' => "{$lead->status} → {$new}"]],
            write: function ($lead) use ($new) {
                $from = $lead->status;
                $lead->status = $new;
                $lead->last_contacted_at = now();
                $lead->save();

                // Mirrors LeadController::updateStatus — status change is not an
                // import, so it does not go through LeadImporter.
                $lead->activities()->create([
                    'type' => LeadActivity::TYPE_STATUS_CHANGE,
                    'payload' => ['from' => $from, 'to' => $new],
                    'user_id' => current_shop_user()?->id,
                ]);

                return ['name' => $lead->name, 'status' => $new];
            },
        );
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
            ['name' => 'update_lead_status', 'description' => 'Move a lead through the funnel (new, sent, replied, demo, won, pass). Identify the lead by business name. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'The business/lead name (fuzzy match).'],
                'status' => ['type' => 'string', 'enum' => Lead::STATUSES],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name', 'status']]],
        ];
    }
}
