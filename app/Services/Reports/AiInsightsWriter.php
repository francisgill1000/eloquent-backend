<?php

namespace App\Services\Reports;

use App\Models\AiSummary;
use App\Models\Shop;
use App\Services\Wa\ClaudeClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Builds a numbers-only metrics payload (selected period vs the previous
 * equal-length period), asks ClaudeClient for a short plain-language narrative,
 * and returns validated JSON. Result is cached per shop_id+from+to for 24h.
 * Every metrics call is scoped by shop_id — no cross-shop leakage.
 */
class AiInsightsWriter
{
    private const CACHE_TTL   = 86400; // 24h
    private const MIN_BOOKINGS = 5;
    private const MIN_HUNT_ACTIONS = 5;

    public function __construct(
        protected ReportsAggregator $aggregator,
        protected ClaudeClient $claude,
    ) {}

    public function summary(int $shopId, Carbon $from, Carbon $to, bool $forceRefresh = false, string $periodType = 'custom'): array
    {
        $key = sprintf('ai_insights:%s:%d:%s:%s', $periodType, $shopId, $from->toDateString(), $to->toDateString());

        if (! $forceRefresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return array_merge($cached, ['cached' => true]);
            }

            // Exact stored row for this (type, window). For rolling30 only, fall
            // back to the latest stored rolling30 row so the morning load stays
            // instant and tolerant of a ±1 day timezone boundary (as before).
            $stored = $this->storedFor($shopId, $periodType, $from, $to);
            if ($stored === null && $periodType === 'rolling30') {
                $stored = $this->latestStored($shopId, 'rolling30');
            }
            if ($stored !== null) {
                return $this->fromStored($stored);
            }
        }

        $shop = Shop::find($shopId);
        $hasBookings = $shop !== null && ((bool) $shop->is_master || $shop->hasModule('bookings'));
        $hasLeads    = $shop !== null && ((bool) $shop->is_master || $shop->hasModule('leads'));

        // One product at a time — Business Hunt takes priority WHEN it has enough
        // activity; a qualifying Hunt shop gets a Hunt-ONLY summary (never mixed).
        // If Hunt has no qualifying activity yet, fall back to the bookings summary
        // so a shop is never left with a Hunt dead-end while it has booking data.
        $qualified = null;

        if ($hasLeads) {
            $hunt = $this->aggregator->huntSummary($shopId, $from, $to);
            $huntActions = (int) $hunt['new_leads'] + array_sum($hunt['moved']);
            if ($huntActions >= self::MIN_HUNT_ACTIONS) {
                $qualified = ['bookings' => null, 'hunt' => $hunt];
            }
        }

        if ($qualified === null && $hasBookings) {
            $insights = $this->aggregator->insightsSummary($shopId, $from, $to);
            if ((int) ($insights['bookings']['scheduled'] ?? 0) >= self::MIN_BOOKINGS) {
                $qualified = ['bookings' => $insights, 'hunt' => null];
            }
        }

        if ($qualified === null) {
            // Neither product has enough data yet — nudge toward the primary one.
            $message = $hasLeads
                ? 'Not enough Business Hunt activity in this period yet to generate an AI summary. Check back once you have a few more leads.'
                : 'Not enough bookings in this period yet to generate an AI summary. Check back once you have a few more.';

            return $this->state('low_data', $message);
        }

        try {
            $recent = $this->recentSummaries($shopId);
            $payload = $this->buildPayload($shopId, $from, $to, $qualified, $recent);
            $reply = $this->claude->reply($this->systemPrompt(), [
                ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
            ]);
            $parsed = $this->parse($reply);
        } catch (\Throwable $e) {
            Log::warning('AiInsightsWriter failed', ['shop_id' => $shopId, 'error' => $e->getMessage()]);
            return $this->state('error', 'Could not generate the AI summary right now. Please try again.');
        }

        if ($parsed === null) {
            return $this->state('error', 'Could not generate the AI summary right now. Please try again.');
        }

        $result = [
            'state'           => 'ok',
            'summary'         => $parsed['summary'],
            'patterns'        => $parsed['patterns'],
            'recommendations' => $parsed['recommendations'],
            'message'         => '',
            'generated_at'    => Carbon::now()->toIso8601String(),
            'cached'          => false,
        ];

        Cache::put($key, $result, self::CACHE_TTL);
        $this->persist($shopId, $from, $to, $parsed, $periodType);

        return $result;
    }

    /**
     * The shop's most recent rolling30 summaries — fed to the model so a new
     * day's summary reads differently from earlier ones.
     *
     * @return array<int, string>
     */
    protected function recentSummaries(int $shopId): array
    {
        return AiSummary::where('shop_id', $shopId)
            ->where('period_type', 'rolling30')
            ->orderByDesc('summary_date')
            ->limit(3)
            ->pluck('summary')
            ->all();
    }

    /** Exact stored summary for one (shop, period_type, window), or null. */
    protected function storedFor(int $shopId, string $periodType, Carbon $from, Carbon $to): ?AiSummary
    {
        return AiSummary::where('shop_id', $shopId)
            ->where('period_type', $periodType)
            ->whereDate('period_from', $from->toDateString())
            ->whereDate('period_to', $to->toDateString())
            ->first();
    }

    /** The shop's most recent stored summary of a given type, or null. */
    protected function latestStored(int $shopId, string $periodType = 'rolling30'): ?AiSummary
    {
        return AiSummary::where('shop_id', $shopId)
            ->where('period_type', $periodType)
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->first();
    }

    /** Shape a stored row into the same response contract as a fresh generation. */
    protected function fromStored(AiSummary $row): array
    {
        return [
            'state'           => 'ok',
            'summary'         => $row->summary,
            'patterns'        => is_array($row->patterns) ? $row->patterns : [],
            'recommendations' => is_array($row->recommendations) ? $row->recommendations : [],
            'message'         => '',
            'generated_at'    => optional($row->updated_at)->toIso8601String() ?? Carbon::now()->toIso8601String(),
            'cached'          => true,
        ];
    }

    /**
     * Upsert one row per (shop, period_type, window). Failure must never break
     * the reply. NOTE: we look up the existing row via storedFor() (which uses
     * whereDate) rather than updateOrCreate with bare-date keys — the period_from/
     * period_to columns are date-cast and persist as 'Y-m-d H:i:s', so an exact
     * updateOrCreate key of toDateString() would miss on sqlite and duplicate.
     *
     * @param array{summary: string, patterns: string[], recommendations: string[]} $parsed
     */
    protected function persist(int $shopId, Carbon $from, Carbon $to, array $parsed, string $periodType): void
    {
        try {
            $attrs = [
                'summary_date'    => Carbon::now()->toDateString(),
                'summary'         => $parsed['summary'],
                'patterns'        => $parsed['patterns'],
                'recommendations' => $parsed['recommendations'],
                'model'           => (string) config('services.anthropic.model'),
            ];

            $existing = $this->storedFor($shopId, $periodType, $from, $to);
            if ($existing !== null) {
                $existing->update($attrs);
            } else {
                AiSummary::create($attrs + [
                    'shop_id'     => $shopId,
                    'period_type' => $periodType,
                    'period_from' => $from->toDateString(),
                    'period_to'   => $to->toDateString(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('AiInsightsWriter persist failed', ['shop_id' => $shopId, 'error' => $e->getMessage()]);
        }
    }

    protected function buildPayload(int $shopId, Carbon $from, Carbon $to, array $qualified, array $recentSummaries = []): array
    {
        $lengthDays = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $prevTo   = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($lengthDays - 1)->startOfDay();

        $payload = [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $lengthDays],
            // Earlier summaries you wrote — vary your framing from these.
            'recent_summaries' => $recentSummaries,
        ];

        if (($insights = $qualified['bookings'] ?? null) !== null) {
            $revenue      = $this->aggregator->revenueSummary($shopId, $from, $to);
            $prevRevenue  = $this->aggregator->revenueSummary($shopId, $prevFrom, $prevTo);
            $prevInsights = $this->aggregator->insightsSummary($shopId, $prevFrom, $prevTo);

            $payload['bookings'] = [
                'current' => [
                    'bookings'          => $insights['bookings'],
                    'rates'             => $insights['rates'],
                    'customers'         => $insights['customers'],
                    'reviews'           => $insights['reviews'],
                    'gross_revenue'     => $revenue['kpis']['gross_revenue'],
                    'avg_booking_value' => $revenue['kpis']['avg_booking_value'],
                    'top_services'      => $revenue['top_services'],
                ],
                'previous' => [
                    'bookings'          => $prevInsights['bookings'],
                    'rates'             => $prevInsights['rates'],
                    'customers'         => $prevInsights['customers'],
                    'reviews'           => $prevInsights['reviews'],
                    'gross_revenue'     => $prevRevenue['kpis']['gross_revenue'],
                    'avg_booking_value' => $prevRevenue['kpis']['avg_booking_value'],
                ],
            ];
        }

        if (($hunt = $qualified['hunt'] ?? null) !== null) {
            $prevHunt = $this->aggregator->huntSummary($shopId, $prevFrom, $prevTo);

            $payload['hunt'] = [
                'current' => [
                    'new_leads'    => $hunt['new_leads'],
                    'pipeline'     => $hunt['pipeline'],
                    'total_leads'  => $hunt['total_leads'],
                    'moved'        => $hunt['moved'],
                    'won'          => $hunt['won'],
                    'credits_used' => $hunt['credits_used'],
                    'searches'     => $hunt['searches'],
                ],
                // pipeline/total_leads are a current snapshot — omit from previous.
                'previous' => [
                    'new_leads'    => $prevHunt['new_leads'],
                    'moved'        => $prevHunt['moved'],
                    'won'          => $prevHunt['won'],
                    'credits_used' => $prevHunt['credits_used'],
                    'searches'     => $prevHunt['searches'],
                ],
            ];
        }

        return $payload;
    }

    /** @return array{summary: string, patterns: string[], recommendations: string[]}|null */
    protected function parse(string $reply): ?array
    {
        $start = strpos($reply, '{');
        $end   = strrpos($reply, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $data = json_decode(substr($reply, $start, $end - $start + 1), true);
        if (! is_array($data)
            || ! isset($data['summary'], $data['patterns'], $data['recommendations'])
            || ! is_string($data['summary'])
            || ! is_array($data['patterns'])
            || ! is_array($data['recommendations'])
        ) {
            return null;
        }

        $strings = fn (array $a) => array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : null,
            $a,
        )));

        $summary = trim($data['summary']);
        if ($summary === '') {
            return null;
        }

        return [
            'summary'         => $summary,
            'patterns'        => array_slice($strings($data['patterns']), 0, 3),
            'recommendations' => array_slice($strings($data['recommendations']), 0, 2),
        ];
    }

    protected function state(string $state, string $message): array
    {
        return [
            'state'           => $state,
            'summary'         => '',
            'patterns'        => [],
            'recommendations' => [],
            'message'         => $message,
            'generated_at'    => Carbon::now()->toIso8601String(),
            'cached'          => false,
        ];
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a plain-language business analyst for a small business. The business may take bookings (a service shop — salon, clinic, laundry) and/or run a "Business Hunt" outbound pipeline (finding and pursuing other businesses as leads).

You will receive a JSON object of computed metrics for the selected period and the previous equal-length period. It contains a "bookings" section and/or a "hunt" section — ONLY the sections the business actually uses are present.

Write a short, encouraging but honest performance summary for the owner, who is NOT technical.

STRICT RULES:
- Summarize ONLY the sections present in the JSON. If "bookings" is absent, say nothing about bookings or revenue; if "hunt" is absent, say nothing about leads.
- Use ONLY the numbers provided. Never invent figures, names, or trends the data does not show. Every statement must be supported by the actual numbers.
- Compare "current" vs "previous" to describe direction (up / down / flat). If a previous value is zero, describe it as a new or first-of-period result rather than citing a percentage change.
- In "hunt": "new_leads" = leads added this period; "pipeline" = the CURRENT count in each funnel stage (new, sent, replied, demo, won, pass); "moved" = how many leads advanced INTO each stage this period; "won" = leads won; "credits_used"/"searches" = search activity.
- No jargon. Refer to money as AED.
- Keep it concise.

Return ONLY a JSON object, no markdown fences, with exactly these keys:
{
  "summary": "2-3 sentence plain-language overview",
  "patterns": ["2-3 short notable patterns"],
  "recommendations": ["1-2 short concrete recommendations"]
}
PROMPT;
    }
}
