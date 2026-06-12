<?php

namespace App\Services\Wa;

use App\Models\Shop;
use App\Support\ServiceCategories;
use App\Support\Wa\Prompts;

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
}
