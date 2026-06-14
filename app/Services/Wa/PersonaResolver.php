<?php

namespace App\Services\Wa;

use App\Models\Shop;
use App\Support\Wa\PromptGenerator;

/**
 * The system prompt is controlled entirely by the shop owner: whatever they
 * saved in the persona field is used verbatim — no services, hours or rules
 * are appended behind the scenes. Owners bake those in (if they want) via the
 * "Generate from profile" button. When no custom prompt is saved yet, a fresh
 * profile-generated prompt is used as the sensible default.
 */
class PersonaResolver
{
    public function promptForShop(?Shop $shop): string
    {
        if (!$shop) {
            return 'You are a friendly, professional assistant. Reply briefly and helpfully.';
        }

        return ($shop->persona && trim($shop->persona) !== '')
            ? $shop->persona
            : PromptGenerator::generate($shop);
    }

    /**
     * The full system prompt sent to Claude. Intentionally identical to the
     * owner's saved prompt (no hidden additions); the $contact argument is
     * kept for call-site compatibility.
     */
    public function systemPrompt(?Shop $shop, ?\App\Models\WaContact $contact = null): string
    {
        return $this->promptForShop($shop);
    }
}
