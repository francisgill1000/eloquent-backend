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
            . "\n\n" . $this->task($kind)
            . "\n\nWrite ONE ready-to-send WhatsApp {$kind} message to THIS business, addressing it by its"
            . " business name and using the details above. A contact person's name is not needed. Do NOT use"
            . " placeholders like {name}, do NOT ask for any more information, and do NOT explain yourself."
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
            '- Open with a warm, personal greeting to the recipient by their business name; a single 👋 is',
            '  welcome, but no more than one emoji in the greeting.',
            '- Lead with a specific hook about the recipient, not a feature dump. It is natural and honest to',
            '  note you came across them while looking for their industry in their area.',
            '- State value in the recipient\'s terms, drawn ONLY from what the sender actually offers (below).',
            '- End with a soft, low-friction question as the CTA (e.g. "Worth a quick 2-min demo?"). Never "buy now".',
            '- No invented statistics, names, or offers. Never claim anything the sender profile does not support.',
            '- You are messaging a BUSINESS. Address it by its business name (e.g. "Hi Marina Barbers").',
            '  You will NOT have a contact person\'s name, and that is completely fine — never ask for one,',
            '  never leave a blank for it, and never write "Dear [Name]".',
            '- Never ask the reader (or the requester) for more information, and never explain what you are doing.',
            '  Always produce the finished message itself, ready to send as-is.',
        ]);
    }

    /** Kind-specific structure. Each message stands on its own — a follow-up is NOT
     *  given the earlier message and must never assume its wording. */
    private function task(string $kind): string
    {
        if ($kind === 'follow-up') {
            return implode("\n", [
                'This is a FOLLOW-UP: the business was already contacted once and hasn\'t replied yet.',
                'Write a short, fresh nudge (1-2 lines) that stands entirely on its own — a different benefit,',
                'a light proof point, or a soft question. Do NOT reference, quote, or assume the wording of any',
                'earlier message, and do NOT ask what was said before. Just write the nudge.',
            ]);
        }

        return implode("\n", [
            'This is a FIRST message — you have not contacted this business before.',
            'Structure it as: a warm one-line greeting, then — if the sender has two or more concrete',
            'offerings below — a short list of 3-5 lines, each starting with a ✅, naming what the sender',
            'does in the recipient\'s terms, then a soft closing question. If the sender has fewer than two',
            'offerings, use 2-4 short lines instead of a bulleted list. Keep the whole message scannable.',
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

        $services = $shop->catalogs()->get(['title', 'description', 'price'])
            ->filter(fn ($s) => trim((string) $s->title) !== '')
            ->map(function ($s) {
                $line = '- ' . trim($s->title);
                if (trim((string) $s->description) !== '') {
                    $line .= ' — ' . trim($s->description);
                }
                if ($s->price !== null) {
                    $line .= ' (AED ' . number_format((float) $s->price, 0) . ')';
                }
                return $line;
            })
            ->implode("\n");
        if ($services !== '') {
            $lines[] = "What they offer:\n" . $services;
        }

        return implode("\n", $lines);
    }
}
