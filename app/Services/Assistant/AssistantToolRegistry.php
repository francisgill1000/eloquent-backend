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
        protected \App\Services\Assistant\Modules\BookingTools $bookings,
        protected \App\Services\Assistant\Modules\ServiceTools $services,
        protected \App\Services\Assistant\Modules\CategoryTools $categories,
        protected \App\Services\Assistant\Modules\StaffTools $staff,
        protected \App\Services\Assistant\Modules\HoursTools $hours,
        protected \App\Services\Assistant\Modules\CustomerTools $customers,
        protected \App\Services\Assistant\Modules\ProfileTools $profile,
        protected \App\Services\Assistant\Modules\AccessTools $access,
        protected \App\Services\Assistant\Modules\HuntReadTools $huntRead,
        protected \App\Services\Assistant\Modules\HuntTools $hunt,
    ) {}

    /** @return array<int, AssistantToolModule> */
    protected function modules(): array
    {
        return [
            $this->legacy,
            $this->bookings,
            $this->services,
            $this->categories,
            $this->staff,
            $this->hours,
            $this->customers,
            $this->profile,
            $this->access,
            $this->huntRead,
            $this->hunt,
        ];
    }

    /** @return array<int, AssistantToolModule> */
    protected function activeModules(?Shop $shop): array
    {
        $mutationsOn = (bool) config('assistant.mutations_enabled', true);

        return array_values(array_filter($this->modules(), function (AssistantToolModule $m) use ($mutationsOn, $shop) {
            // Global kill-switch: hide every data-changing module when off.
            if (! $mutationsOn && $m instanceof MutatingTool) {
                return false;
            }
            // Product gate: universal (null) modules and master shops see all;
            // otherwise the shop must have the module enabled. A null shop
            // (no context, e.g. a bare defs() call in tests) sees everything.
            $key = $m->moduleKey();
            if ($key === null || $shop === null || $shop->is_master) {
                return true;
            }
            return $shop->hasModule($key);
        }));
    }

    /**
     * Tool schemas for this shop AND this user. Filtered twice: by product module
     * (activeModules) and by the acting user's permissions, so the model is never
     * shown a tool it will only get 'no_permission' back from. The acting user
     * defaults to the request's ShopUser; null (owner/untagged) sees everything.
     *
     * @return array<int, array<string, mixed>>
     */
    public function defs(?Shop $shop = null, ?\App\Models\ShopUser $actingUser = null): array
    {
        $user = $actingUser ?? current_shop_user();

        $defs = [];
        foreach ($this->activeModules($shop) as $module) {
            foreach ($module->visibleToolDefs($user) as $def) {
                $defs[] = $def;
            }
        }
        return $defs;
    }

    public function execute(Shop $shop, string $tool, array $input, bool $userConfirmed = false): string
    {
        $call = new ToolCall(
            shop: $shop,
            actingUser: current_shop_user(),
            tool: $tool,
            input: $input,
            confirmed: $userConfirmed || (bool) ($input['confirmed'] ?? false),
            userConfirmed: $userConfirmed,
        );

        // Every owner-assistant tool call passes through here, so this is where
        // the record of what actually ran belongs. A tool the model invented is
        // logged too — that is exactly the kind of thing worth seeing later.
        $started = hrtime(true);
        $result = ['error' => 'unknown_tool'];

        foreach ($this->activeModules($shop) as $module) {
            if ($module->handles($tool)) {
                $result = $module->run($call);
                break;
            }
        }

        app(AssistantCallLog::class)->recordSafely(
            $shop->id, $tool, $input, $result, $userConfirmed,
            (int) ((hrtime(true) - $started) / 1_000_000),
        );

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }
}
