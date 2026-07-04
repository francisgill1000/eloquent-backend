<?php
namespace App\Services\Assistant;

use App\Models\Shop;
use App\Services\Assistant\Contracts\AssistantToolModule;
use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ToolCall;

/**
 * Aggregates every assistant tool module's schemas and routes each tool call to
 * its owning module. Read modules are always active; MutatingTool modules are
 * hidden when config('assistant.mutations_enabled') is false (the kill-switch).
 */
class AssistantToolRegistry
{
    public function __construct(
        protected OwnerAssistantTools $legacy,
        // Later plans add: protected BookingTools $bookings, etc.
    ) {}

    /** @return array<int, AssistantToolModule> */
    protected function modules(): array
    {
        return [$this->legacy];
    }

    /** @return array<int, AssistantToolModule> */
    protected function activeModules(): array
    {
        $mutationsOn = (bool) config('assistant.mutations_enabled', true);

        return array_values(array_filter(
            $this->modules(),
            fn (AssistantToolModule $m) => $mutationsOn || ! $m instanceof MutatingTool,
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function defs(): array
    {
        $defs = [];
        foreach ($this->activeModules() as $module) {
            foreach ($module->toolDefs() as $def) {
                $defs[] = $def;
            }
        }
        return $defs;
    }

    public function execute(Shop $shop, string $tool, array $input): string
    {
        $call = new ToolCall(
            shop: $shop,
            actingUser: current_shop_user(),
            tool: $tool,
            input: $input,
            confirmed: (bool) ($input['confirmed'] ?? false),
        );

        foreach ($this->activeModules() as $module) {
            if ($module->handles($tool)) {
                return json_encode($module->run($call), JSON_UNESCAPED_UNICODE);
            }
        }

        return json_encode(['error' => 'unknown_tool'], JSON_UNESCAPED_UNICODE);
    }
}
