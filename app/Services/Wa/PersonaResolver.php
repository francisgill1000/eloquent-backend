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

        $paymentIntegrity = "PAYMENT RULE (critical): a customer saying they paid — 'done', 'paid', 'sent', 'finished' — is NOT proof of payment. "
            . "Never thank them for paying, never say payment is received, and never call a booking paid or confirmed-by-payment unless the check_payment tool returned paid:true in this very reply. "
            . "If they claim they paid, call check_payment first; if it returns paid:false, politely tell them you can't see the payment yet and share the payment link again.\n\n";

        $servicesDisplay = "SERVICES LIST: when the customer asks what services, treatments or prices you offer (e.g. 'what do you offer?', 'show me your services', 'price list', 'menu'), reply with one short friendly sentence and then put the token [[services]] on its own line. "
            . "The app renders the full list of services with their prices in place of that token, so do NOT type the services or prices out yourself. "
            . "Use the token only for that general request — not when the customer is asking about one specific service.\n\n";

        return $dateContext . $paymentIntegrity . $servicesDisplay . $this->promptForShop($shop);
    }
}
