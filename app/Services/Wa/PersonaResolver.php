<?php

namespace App\Services\Wa;

use App\Models\BotPrompt;
use App\Models\Shop;
use App\Models\ShopCustomer;
use App\Models\WaAccount;
use App\Support\ServiceCategories;
use App\Support\Wa\Prompts;

/**
 * Pick the system prompt (and whether the onboarding tool is offered) for an
 * inbound message. Folds the Node service's persona / sales-override / shop
 * routing into direct lookups. Ported from whatsapp-autoreply lib/personas.js,
 * lib/salesPrompt.js and lib/router.js.
 */
class PersonaResolver
{
    /** @return array{prompt: string, offerTools: bool} */
    public function resolve(WaAccount $account, string $from): array
    {
        if (!$this->isSalesNumber($account)) {
            // Tenant number: custom persona if the master set one, else the
            // category-based default. Never the onboarding tool.
            return ['prompt' => $this->promptForShop($account->shop), 'offerTools' => false];
        }

        // Sales number: an active master-panel override wins for everyone —
        // a live persona test, no onboarding.
        if ($override = $this->salesOverride()) {
            return ['prompt' => $override->body, 'offerTools' => false];
        }

        // Known customer of the shop owning this number → that shop's assistant.
        if ($shop = $this->customerShop($account, $from)) {
            return [
                'prompt' => Prompts::provider($shop->name, ServiceCategories::name($shop->category_id)),
                'offerTools' => false,
            ];
        }

        // Lead → the default Rezzy sales assistant (the only path that may onboard).
        return ['prompt' => Prompts::REZZY_SALES, 'offerTools' => true];
    }

    /**
     * The assistant prompt for a shop regardless of channel: the master-set
     * persona when present, else the category-based default. Used by the
     * tenant-WA branch above and by in-app Live Chat (which has no WaAccount).
     */
    public function promptForShop(?Shop $shop): string
    {
        return ($shop?->persona && trim($shop->persona) !== '')
            ? $shop->persona
            : Prompts::provider($shop?->name ?? 'this business', ServiceCategories::name($shop?->category_id));
    }

    public function isSalesNumber(WaAccount $account): bool
    {
        $salesId = (string) config('services.whatsapp.sales_phone_number_id');

        return $salesId !== '' && $account->phone_number_id === $salesId;
    }

    /** The active non-default master-panel prompt, or null for normal behaviour. */
    public function salesOverride(): ?BotPrompt
    {
        $active = BotPrompt::where('is_active', true)->first();

        return ($active && !$active->is_default && trim((string) $active->body) !== '') ? $active : null;
    }

    private function customerShop(WaAccount $account, string $from): ?Shop
    {
        if (!$account->shop_id) {
            return null;
        }

        $normalized = ShopCustomer::normalize($from);
        if ($normalized === '') {
            return null;
        }

        $tail = strlen($normalized) > 9 ? substr($normalized, -9) : $normalized;
        $isCustomer = ShopCustomer::where('shop_id', $account->shop_id)
            ->where('whatsapp_normalized', 'LIKE', '%' . $tail)
            ->exists();

        return $isCustomer ? $account->shop : null;
    }
}
