<?php

namespace App\Services\Leads;

use App\Models\Shop;
use App\Services\Wa\ClaudeClient;
use Illuminate\Support\Facades\Cache;

/**
 * Turns whatever the user types into the Business Hunt box — a keyword, or a
 * plain-language goal like "find me some customer for my salon shop" — into a
 * real Google-Maps business-type search, using the shop's own profile to pick a
 * sensible B2B target.
 *
 * The interpretation is cached per shop+query so repeating the same search is
 * stable and never re-bills a credit through a different keyword. On any AI
 * failure the caller falls back to searching the raw text literally.
 */
class SearchInterpreter
{
    public function __construct(private ClaudeClient $claude)
    {
    }

    /**
     * @return array{keyword: string, area: ?string}
     *
     * @throws \RuntimeException on AI failure / unparseable output (caller falls back).
     */
    public function interpret(Shop $shop, string $rawQuery, ?string $rawArea): array
    {
        $q = trim($rawQuery);
        $key = 'lead_query:' . $shop->id . ':' . md5(mb_strtolower($q) . '|' . mb_strtolower((string) $rawArea));

        return Cache::remember($key, now()->addDays(30), function () use ($shop, $q, $rawArea) {
            $raw = $this->claude->reply($this->system($shop), [
                ['role' => 'user', 'content' => $q],
            ]);

            $json = $this->extractJson($raw);
            $keyword = trim((string) ($json['keyword'] ?? ''));
            if ($keyword === '') {
                throw new \RuntimeException('SearchInterpreter: model returned no keyword.');
            }
            $area = trim((string) ($json['area'] ?? ''));
            // The Google source returns nothing without an area, so always
            // attach one: the model's area, else the caller's, else the shop's
            // own location, else Dubai (the largest UAE market).
            $area = $area !== '' ? $area : ($rawArea ?: ($shop->location ?: 'Dubai'));

            return ['keyword' => $keyword, 'area' => $area];
        });
    }

    private function system(Shop $shop): string
    {
        $profile = ['The user runs this business (they are searching for other businesses to sell to or partner with):'];
        $profile[] = 'Business name: ' . ($shop->name ?: '(unnamed)');
        if ($label = $shop->categoryLabel()) {
            $profile[] = 'Industry: ' . $label;
        }
        if ($shop->location) {
            $profile[] = 'Location: ' . $shop->location;
        }
        $services = $shop->catalogs()->get(['title'])
            ->filter(fn ($s) => trim((string) $s->title) !== '')
            ->map(fn ($s) => trim($s->title))
            ->take(8)->implode(', ');
        if ($services !== '') {
            $profile[] = 'Services: ' . $services;
        }

        return implode("\n", $profile) . "\n\n" . implode("\n", [
            'You turn the user\'s request into ONE Google-Maps business-search term for a B2B lead-finding tool.',
            'Rules:',
            '- If the request already names a concrete business TYPE to look for (e.g. "car wash in Dubai Marina",',
            '  "gyms", "restaurants in Deira"), keep that type — just clean it into a short keyword.',
            '- If the request is a GOAL with no concrete type (e.g. "find me customers", "who can I sell to",',
            '  "customers for my salon"), infer the single best type of BUSINESS that would realistically BUY FROM',
            '  or PARTNER WITH the user, based on their profile above. NEVER return the user\'s own business type or',
            '  a direct competitor. Example: a hair salon looking for customers -> hotels, gyms, spas, wedding',
            '  planners, event companies, modelling agencies — NOT other salons.',
            '- Only real business categories that exist on Google Maps (hotels, gyms, wedding planners, offices,',
            '  restaurants, clinics, …). Never individuals/consumers.',
            '- ALWAYS include an area (a UAE city/emirate/neighbourhood). Use the area from the request if given,',
            '  else the user\'s own location above, else "Dubai". Never leave the area empty.',
            '',
            'Return ONLY JSON, no prose: {"keyword": "...", "area": "..."}',
        ]);
    }

    /** Pull the first JSON object out of a model reply that may include prose. */
    private function extractJson(string $raw): array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new \RuntimeException('SearchInterpreter: no JSON object in model reply.');
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('SearchInterpreter: could not parse model JSON.');
        }
        return $decoded;
    }
}
