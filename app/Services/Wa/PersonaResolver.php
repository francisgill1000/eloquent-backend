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
     * The full system prompt sent to Claude: the owner's persona, grounded with
     * the current date so the model resolves dates correctly (the model has no
     * inherent sense of "today" and would otherwise guess a past year). The
     * $contact argument is kept for call-site compatibility.
     */
    public function systemPrompt(?Shop $shop, ?\App\Models\WaContact $contact = null): string
    {
        $today = \Illuminate\Support\Carbon::now('Asia/Dubai');
        $dateContext = "Today is {$today->format('l, j F Y')} (Asia/Dubai timezone), so the current year is {$today->year}. "
            . "Whenever the customer mentions a date — 'today', 'tomorrow', 'this Friday', '18 June', 'the 25th' — work out the exact YYYY-MM-DD yourself using today's date, always in {$today->year} or later, never a past year. "
            . "Never ask the customer to type a date in a specific format; understand whatever they say naturally.\n\n";

        return $dateContext . $this->promptForShop($shop);
    }
}
