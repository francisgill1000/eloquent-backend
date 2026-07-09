<?php

namespace App\Services\Reports;

use App\Models\AiSummary;
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

    public function __construct(
        protected ReportsAggregator $aggregator,
        protected ClaudeClient $claude,
    ) {}

    public function summary(int $shopId, Carbon $from, Carbon $to, bool $forceRefresh = false): array
    {
        $key = sprintf('ai_insights:%d:%s:%s', $shopId, $from->toDateString(), $to->toDateString());

        if (! $forceRefresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return array_merge($cached, ['cached' => true]);
            }
        }

        $insights = $this->aggregator->insightsSummary($shopId, $from, $to);

        if ((int) ($insights['bookings']['scheduled'] ?? 0) < self::MIN_BOOKINGS) {
            return $this->state('low_data', 'Not enough bookings in this period yet to generate an AI summary. Check back once you have a few more.');
        }

        try {
            $recent = $this->recentSummaries($shopId);
            $payload = $this->buildPayload($shopId, $from, $to, $insights, $recent);
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
        $this->persistDaily($shopId, $from, $to, $parsed);

        return $result;
    }

    /**
     * The shop's most recent stored summaries — fed to the model so a new day's
     * summary is framed differently from earlier ones (never repetitive).
     *
     * @return array<int, string>
     */
    protected function recentSummaries(int $shopId): array
    {
        return AiSummary::where('shop_id', $shopId)
            ->orderByDesc('summary_date')
            ->limit(3)
            ->pluck('summary')
            ->all();
    }

    /**
     * Upsert one row per shop per day. Failure here must never break the reply.
     *
     * @param array{summary: string, patterns: string[], recommendations: string[]} $parsed
     */
    protected function persistDaily(int $shopId, Carbon $from, Carbon $to, array $parsed): void
    {
        try {
            AiSummary::updateOrCreate(
                ['shop_id' => $shopId, 'summary_date' => Carbon::now()->toDateString()],
                [
                    'period_from'     => $from->toDateString(),
                    'period_to'       => $to->toDateString(),
                    'summary'         => $parsed['summary'],
                    'patterns'        => $parsed['patterns'],
                    'recommendations' => $parsed['recommendations'],
                    'model'           => (string) config('services.anthropic.model'),
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('AiInsightsWriter persist failed', ['shop_id' => $shopId, 'error' => $e->getMessage()]);
        }
    }

    protected function buildPayload(int $shopId, Carbon $from, Carbon $to, array $insights, array $recentSummaries = []): array
    {
        $lengthDays = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $prevTo   = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($lengthDays - 1)->startOfDay();

        $revenue      = $this->aggregator->revenueSummary($shopId, $from, $to);
        $prevRevenue  = $this->aggregator->revenueSummary($shopId, $prevFrom, $prevTo);
        $prevInsights = $this->aggregator->insightsSummary($shopId, $prevFrom, $prevTo);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $lengthDays],
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
            // Earlier summaries you wrote — vary your framing from these.
            'recent_summaries' => $recentSummaries,
        ];
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
You are a plain-language business analyst for a service business (salon, clinic, laundry, etc.).
You will receive a JSON object of computed metrics for the selected period and the previous equal-length period.

Write a short, encouraging but honest performance summary for the shop owner, who is NOT technical.

STRICT RULES:
- Use ONLY the numbers provided. Never invent figures, names, or trends the data does not show. Every statement must be supported by the actual performance numbers.
- Compare "current" vs "previous" to describe direction (up / down / flat). If a previous value is zero, describe it as a new or first-of-period result rather than citing a percentage change.
- If "recent_summaries" is provided, those are summaries you wrote on earlier days. Do NOT reuse their opening, wording, or framing — take a fresh angle and lead with what has changed. Never invent facts just to seem different; accuracy to the numbers always wins over novelty.
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
