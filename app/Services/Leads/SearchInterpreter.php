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

            return ['keyword' => $keyword, 'area' => $area !== '' ? $area : ($rawArea ?: null)];
        });
    }

    private function system(Shop $shop): string
    {
        $profile = ['The user runs this business (they are searching for other businesses to sell to or partner with):'];
        $profile[] = 'Business name: ' . ($shop->name ?: '(unnamed)');
        if ($label = $shop->categoryLabel()) {
            $profile[] = 'Industry: ' . $label;
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
            '- If the request already names a concrete business TYPE (e.g. "car wash in Dubai Marina", "gyms",',
            '  "restaurants in Deira"), keep that type — just clean it into a short keyword and pull out the area.',
            '- If the request is a GOAL with no concrete type (e.g. "find me customers", "who can I sell to",',
            '  "customers for my salon"), infer the single best type of BUSINESS that would realistically be a',
            '  customer or referral partner for the user\'s business, based on their profile above.',
            '- Only real business categories that exist on Google Maps (hotels, gyms, wedding planners, offices,',
            '  restaurants, clinics, …). Never individuals/consumers.',
            '- Include an area only if the user mentioned one or it is clearly implied; otherwise leave it empty.',
            '',
            'Return ONLY JSON, no prose: {"keyword": "...", "area": "..."}  (area may be an empty string).',
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
