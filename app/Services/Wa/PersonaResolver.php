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
     * business facts (services, prices, hours, current Dubai time) so the
     * assistant answers from real data instead of guessing.
     */
    public function systemPrompt(?Shop $shop): string
    {
        $prompt = $this->promptForShop($shop);

        return $shop ? $prompt . "\n\n" . ShopFacts::for($shop) : $prompt;
    }
}
