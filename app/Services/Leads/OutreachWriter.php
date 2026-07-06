<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\Shop;
use App\Services\Wa\ClaudeClient;

/**
 * Writes WhatsApp cold-outreach copy for a shop's leads using Claude.
 *
 * Two modes:
 *  - templatesForShop(): a reusable opening + follow-up TEMPLATE (keeps the
 *    literal {name}/{category}/{area} placeholders, signs as {shop}).
 *  - personalizeForLead(): one ready-to-send message for a specific lead
 *    (real values, no placeholders).
 *
 * The model, key, retries and error handling all live in ClaudeClient.
 */
class OutreachWriter
{
    public function __construct(private ClaudeClient $claude)
    {
    }

    /** @return array{opening: string, followup: string} */
    public function templatesForShop(Shop $shop): array
    {
        $system = $this->rules()
            . "\n\n" . $this->shopProfile($shop)
            . "\n\nWrite a reusable OPENING and FOLLOW-UP template this shop can send to any prospect it finds."
            . " Keep these literal placeholders in the text so they fill per-lead: {name} (the prospect's business name),"
            . " {category} (the prospect's industry), {area} (the prospect's location). Sign as {shop} where natural."
            . "\n\nReturn ONLY a JSON object, no prose, exactly: {\"opening\": \"...\", \"followup\": \"...\"}";

        $raw = $this->claude->reply($system, [
            ['role' => 'user', 'content' => 'Write the opening and follow-up templates.'],
        ]);

        $json = $this->extractJson($raw);
        $opening = trim((string) ($json['opening'] ?? ''));
        $followup = trim((string) ($json['followup'] ?? ''));
        if ($opening === '' || $followup === '') {
            throw new \RuntimeException('OutreachWriter: model returned no usable templates.');
        }

        return ['opening' => $opening, 'followup' => $followup];
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
            . "\n\nWrite ONE ready-to-send WhatsApp {$kind} message to THIS prospect."
            . " Use their real name and details — do NOT use placeholders like {name}."
            . " Return ONLY the message text, nothing else.";

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

    /** Pull the first JSON object out of a model reply that may include prose. */
    private function extractJson(string $raw): array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new \RuntimeException('OutreachWriter: no JSON object in model reply.');
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('OutreachWriter: could not parse model JSON.');
        }
        return $decoded;
    }
}
