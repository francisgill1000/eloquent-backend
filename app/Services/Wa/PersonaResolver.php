<?php

namespace App\Services\Wa;

use App\Models\Shop;
use App\Support\ServiceCategories;
use App\Support\Wa\Prompts;
use App\Support\Wa\ShopFacts;

/**
 * Every number speaks as its shop — the master-set persona when present,
 * else the category-based default. There is no special sales persona and no
 * in-chat onboarding: personas are managed per shop (the "shop flow").
 */
class PersonaResolver
{
    public function promptForShop(?Shop $shop): string
    {
        return ($shop?->persona && trim($shop->persona) !== '')
            ? $shop->persona
            : Prompts::provider($shop?->name ?? 'this business', ServiceCategories::name($shop?->category_id));
    }

    /**
     * The full system prompt sent to Claude: the persona plus the shop's live
     * business facts (services, prices, hours, current Dubai time) and — when
     * the thread belongs to a recognised customer — their identity and
     * upcoming bookings, so returning customers are greeted by name.
     */
    public function systemPrompt(?Shop $shop, ?\App\Models\WaContact $contact = null): string
    {
        $prompt = $this->promptForShop($shop);

        if (!$shop) {
            return $prompt;
        }

        // The owner can change the persona mid-conversation. Make the system
        // prompt authoritative so the assistant adopts the new identity/rules
        // immediately, instead of mimicking the style of its earlier replies
        // still visible in the chat history.
        $override = "\n\nThese instructions are current and authoritative. If earlier assistant replies "
            . "in this conversation used a different persona, tone, or rules, disregard that completely "
            . "and follow ONLY the instructions above for every reply from now on.";

        return $prompt . "\n\n" . ShopFacts::for($shop, $contact) . $override;
    }
}
