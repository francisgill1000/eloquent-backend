<?php
namespace App\Services\Assistant\Modules;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ResolvesLeads;
use App\Services\Assistant\Support\ToolCall;
use App\Services\Leads\LeadImporter;
use App\Services\Leads\LeadSearchService;
use App\Services\Leads\SearchInterpreter;

/**
 * Owner-assistant Business Hunt tools (leads module): a CACHE-ONLY business
 * search (never spends a credit), save cached results to the pipeline, and move
 * a lead through the funnel. Saves and status changes are confirm-gated via
 * MutatingTool; the search is a free read.
 */
class HuntTools extends MutatingTool
{
    use ResolvesLeads;

    public function __construct(
        protected LeadSearchService $search,
        protected SearchInterpreter $interpreter,
        protected LeadImporter $importer,
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
     * Cache-only business search: returns businesses already discovered by a
     * prior search (the shared cache) — FREE, never debits a Hunt credit. A
     * brand-new live search that spends a credit is intentionally NOT available
     * by voice; the owner runs that from the Hunt screen.
     */
    private function searchBusinesses(ToolCall $call): array
    {
        if (trim((string) $call->get('category')) === '') {
            return ['error' => 'not_found', 'what' => 'missing_category'];
        }

        [$keyword, $area] = $this->interpret($call);
        $rows = $this->search->cached($keyword, $area);

        if ($rows === null) {
            return ['error' => 'no_cached_results', 'searched_for' => $keyword, 'area' => $area];
        }

        return [
            'count' => count($rows),
            'from_cache' => true,
            'searched_for' => $keyword,
            'area' => $area,
            'sample' => array_slice(array_map(fn ($r) => $r['name'] ?? 'Unknown', $rows), 0, 5),
        ];
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
            ['name' => 'search_businesses', 'description' => 'Look up businesses from earlier searches (FREE — never spends a credit). Give a category (e.g. "gyms", "hotels") and an optional area. Returns matches already in the search cache; if none are cached it returns no_cached_results — then tell the owner to run a new live search from the Hunt screen. Use save_leads to save results to the pipeline.', 'input_schema' => ['type' => 'object', 'properties' => [
                'category' => ['type' => 'string', 'description' => 'Business type to look for, e.g. "gyms in Dubai Marina".'],
                'area' => ['type' => 'string', 'description' => 'Optional UAE area/city.'],
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
