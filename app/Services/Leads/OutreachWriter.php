<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\Shop;
use App\Services\Wa\ClaudeClient;

/**
 * Writes WhatsApp cold-outreach copy for a shop's leads using Claude.
 *
 * personalizeForLead(): one ready-to-send message for a specific lead (real
 * values, no placeholders).
 *
 * The model, key, retries and error handling all live in ClaudeClient.
 */
class OutreachWriter
{
    public function __construct(private ClaudeClient $claude)
    {
    }

    public function personalizeForLead(Shop $shop, Lead $lead, string $kind): string
    {
        $kind = $kind === 'followup' ? 'follow-up' : 'opening';

        $leadLines = array_filter([
            'Business name: ' . ($lead->name ?: '(unknown)'),
            $lead->categoryLabel() ? 'Industry: ' . $lead->categoryLabel() : null,
            $lead->area() ? 'Area: ' . $lead->area() : null,
        ]);

        $system = $this->rules()
            . "\n\n" . $this->shopProfile($shop)
            . "\n\nThe specific prospect you are messaging:\n" . implode("\n", $leadLines)
            . "\n\nWrite ONE ready-to-send WhatsApp {$kind} message to THIS business, addressing it by its"
            . " business name and using the details above. Do NOT use placeholders like {name}, do NOT ask for"
            . " any more information (a contact person's name is not needed), and do NOT explain yourself."
            . " Output ONLY the finished message text, nothing else.";

        $msg = trim($this->claude->reply($system, [
            ['role' => 'user', 'content' => "Write the {$kind} message."],
        ]));

        if ($msg === '') {
            throw new \RuntimeException('OutreachWriter: model returned an empty message.');
        }

        return $msg;
    }

    /** Shared copy rules that make the output impactful rather than generic. */
    private function rules(): string
    {
        return implode("\n", [
            'You write WhatsApp cold-outreach for a business reaching out to prospective business customers.',
            'Rules:',
            '- Short: opening 2-4 short lines; follow-up 1-2 lines. Long WhatsApp messages get ignored.',
            '- Open with a specific hook about the recipient (their business or industry), not a feature dump.',
            '- State ONE value prop relevant to the sender\'s offering and the recipient\'s industry.',
            '- End with a soft, low-friction question as the CTA (e.g. "Worth a quick 2-min demo?"). Never "buy now".',
            '- The follow-up must take a NEW angle (a proof point or a question), never repeat the opening.',
            '- No invented statistics, names, or offers. Plain text; at most one light emoji.',
            '- You are messaging a BUSINESS. Address it by its business name (e.g. "Hi Marina Barbers").',
            '  You will NOT have a contact person\'s name, and that is completely fine — never ask for one,',
            '  never leave a blank for it, and never write "Dear [Name]".',
            '- Never ask the reader (or the requester) for more information, and never explain what you are doing.',
            '  Always produce the finished message itself, ready to send as-is.',
        ]);
    }

    /** Compact profile of the sending shop, built from its own data. */
    private function shopProfile(Shop $shop): string
    {
        $lines = ['The sender (you are writing on their behalf):'];
        $lines[] = 'Business name: ' . ($shop->name ?: '(unnamed)');
        if ($label = $shop->categoryLabel()) {
            $lines[] = 'Industry: ' . $label;
        }
        if ($shop->location) {
            $lines[] = 'Location: ' . $shop->location;
        }

        $services = $shop->catalogs()->get(['title', 'price'])
            ->filter(fn ($s) => trim((string) $s->title) !== '')
            ->map(fn ($s) => '- ' . trim($s->title)
                . ($s->price !== null ? ' (AED ' . number_format((float) $s->price, 0) . ')' : ''))
            ->implode("\n");
        if ($services !== '') {
            $lines[] = "What they offer:\n" . $services;
        }

        return implode("\n", $lines);
    }
}
