<?php
namespace App\Services\Assistant\Modules;

use App\Models\Lead;
use App\Services\Assistant\Support\AssistantActions;
use App\Services\Assistant\Support\AssistantModule;
use App\Services\Assistant\Support\ResolvesLeads;
use App\Services\Assistant\Support\ToolCall;
use App\Services\Credits\HuntCreditService;

/**
 * Owner-assistant Business Hunt READ tools (leads module). Non-mutating, so they
 * survive the assistant.mutations_enabled kill-switch — mirrors how the booking
 * read tools live in OwnerAssistantTools, separate from BookingTools.
 */
class HuntReadTools extends AssistantModule
{
    use ResolvesLeads;

    public function __construct(
        protected HuntCreditService $credits,
        protected AssistantActions $actions,
    ) {}

    public function moduleKey(): ?string
    {
        return 'leads';
    }

    protected function permissions(): array
    {
        // Leads has no RBAC permission (module-gated only) — null = no check.
        return [
            'hunt_credits' => null,
            'list_leads' => null,
            'find_lead' => null,
            'open_lead' => null,
        ];
    }

    protected function handle(ToolCall $call): array
    {
        return match ($call->tool) {
            'hunt_credits' => ['credits' => $this->credits->balance($call->shop)],
            'list_leads' => $this->list($call),
            'find_lead' => $this->find($call),
            'open_lead' => $this->open($call),
            default => ['error' => 'unknown_tool'],
        };
    }

    private function list(ToolCall $call): array
    {
        $funnel = array_fill_keys(Lead::STATUSES, 0);
        foreach (
            Lead::forShop($call->shop->id)
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status') as $st => $c
        ) {
            $funnel[$st] = (int) $c;
        }

        $result = ['total' => array_sum($funnel), 'funnel' => $funnel];

        // Optional status filter → up to 8 names for a spoken summary.
        $status = strtolower(trim((string) $call->get('status')));
        if ($status !== '') {
            if (! in_array($status, Lead::STATUSES, true)) {
                return ['error' => 'invalid_status'];
            }
            $result['leads'] = Lead::forShop($call->shop->id)
                ->where('status', $status)
                ->orderByDesc('id')->limit(8)
                ->pluck('name')->all();
        }

        return $result;
    }

    private function find(ToolCall $call): array
    {
        $lead = $this->resolveLead($call);
        if (is_array($lead)) {
            return $lead; // notFound / ambiguous
        }

        return [
            'name' => $lead->name,
            'status' => $lead->status,
            'phone' => $lead->phone,
            'whatsapp' => $lead->whatsapp,
            'category' => $lead->category,
            'address' => $lead->address,
            'last_contacted' => $lead->last_contacted_at?->toDateString(),
        ];
    }

    private function open(ToolCall $call): array
    {
        $lead = $this->resolveLead($call);
        if (is_array($lead)) {
            return $lead;
        }
        $this->actions->navigate("/leads/{$lead->id}");

        return ['opening' => true, 'name' => $lead->name];
    }

    public function toolDefs(): array
    {
        $name = ['name' => ['type' => 'string', 'description' => 'The business/lead name (fuzzy match).']];

        return [
            ['name' => 'hunt_credits', 'description' => 'The shop\'s current Business Hunt credit balance (1 credit = one live search).', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'list_leads', 'description' => 'The lead pipeline: a total plus a count for each funnel stage (new, sent, replied, demo, won, pass). Pass a status to also get up to 8 lead names in that stage.', 'input_schema' => ['type' => 'object', 'properties' => [
                'status' => ['type' => 'string', 'enum' => Lead::STATUSES],
            ]]],
            ['name' => 'find_lead', 'description' => 'Look up one saved lead by business name and return its funnel status and contact details.', 'input_schema' => ['type' => 'object', 'properties' => $name, 'required' => ['name']]],
            ['name' => 'open_lead', 'description' => 'Open/show a lead\'s detail page for the owner in the app (redirects them to it). Use whenever the owner asks to open, show, view, or see a lead. Pass the business name.', 'input_schema' => ['type' => 'object', 'properties' => $name, 'required' => ['name']]],
        ];
    }
}
