<?php
namespace App\Services\Assistant;

use App\Models\Shop;
use App\Services\Assistant\Contracts\AssistantToolModule;
use App\Services\Assistant\Support\ToolCall;
use App\Services\Reports\ReportsAggregator;
use App\Services\Reports\AiInsightsWriter;
use App\Support\Assistant\PeriodResolver;
use Illuminate\Support\Facades\DB;

/**
 * Tools the owner voice assistant can call. Every method is scoped to the
 * passed-in $shop — the controller passes the authenticated shop, so cross-shop
 * access is impossible. defs() returns Anthropic tool schemas; execute() runs
 * one tool and returns a JSON string for the tool-result message.
 */
class OwnerAssistantTools implements AssistantToolModule
{
    public function __construct(
        protected ReportsAggregator $aggregator,
        protected AiInsightsWriter $writer,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function toolDefs(): array
    {
        return static::defs();
    }

    public function handles(string $tool): bool
    {
        foreach (static::defs() as $def) {
            if (($def['name'] ?? null) === $tool) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    public function run(ToolCall $call): array
    {
        return json_decode($this->execute($call->shop, $call->tool, $call->input), true) ?? [];
    }

    public static function defs(): array
    {
        $period = [
            'type' => 'string',
            'enum' => ['today', 'yesterday', 'this_week', 'this_month', 'last_month', 'this_year'],
            'description' => 'Time range for the report.',
        ];

        return [
            [
                'name' => 'get_revenue',
                'description' => 'Total revenue, booking counts (completed/cancelled), average booking value and top services for a period. Use for "how much did I make".',
                'input_schema' => ['type' => 'object', 'properties' => ['period' => $period], 'required' => ['period']],
            ],
            [
                'name' => 'get_top_services',
                'description' => 'Most-booked services ranked by count and revenue for a period.',
                'input_schema' => ['type' => 'object', 'properties' => ['period' => $period], 'required' => ['period']],
            ],
            [
                'name' => 'get_staff_performance',
                'description' => 'Per-staff bookings, revenue and completion/cancellation rates for a period.',
                'input_schema' => ['type' => 'object', 'properties' => ['period' => $period], 'required' => ['period']],
            ],
            [
                'name' => 'get_busy_times',
                'description' => 'Busiest days of week and hours for a period.',
                'input_schema' => ['type' => 'object', 'properties' => ['period' => $period], 'required' => ['period']],
            ],
            [
                'name' => 'get_ai_summary',
                'description' => 'AI-written plain-language performance summary for a period: a short overview, notable patterns, and recommendations. Use for "how are we doing" / "give me my AI summary".',
                'input_schema' => ['type' => 'object', 'properties' => ['period' => $period], 'required' => ['period']],
            ],
            [
                'name' => 'get_bookings',
                'description' => 'List bookings for a specific date OR a period, optionally filtered by status. Returns a count and up to 8 bookings.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'date' => ['type' => 'string', 'description' => 'A single date, YYYY-MM-DD. Optional.'],
                    'period' => $period,
                    'status' => ['type' => 'string', 'enum' => ['booked', 'completed', 'cancelled', 'queued']],
                ]],
            ],
            // The write tools that used to live here — cancel_booking,
            // update_booking_status, update_hours, update_service_price — are
            // superseded by the gated domain modules (BookingTools, HoursTools,
            // ServiceTools). They are removed from defs() to avoid duplicate
            // tool names; their execute() arms remain only so the legacy unit
            // tests (OwnerAssistantMutationTest) stay green until fully retired.
        ];
    }

    public function execute(Shop $shop, string $tool, array $input): string
    {
        $result = match ($tool) {
            'get_revenue'           => $this->revenue($shop, $input),
            'get_top_services'      => $this->aggregatorFor($shop, $input, 'servicesSummary'),
            'get_staff_performance' => $this->aggregatorFor($shop, $input, 'staffSummary'),
            'get_busy_times'        => $this->aggregatorFor($shop, $input, 'timePatternsSummary'),
            'get_ai_summary'        => $this->aiSummary($shop, $input),
            'get_bookings'          => $this->bookings($shop, $input),
            'cancel_booking'        => $this->cancelBooking($shop, $input),
            'update_booking_status' => $this->updateStatus($shop, $input),
            'update_hours'          => $this->updateHours($shop, $input),
            'update_service_price'  => $this->updatePrice($shop, $input),
            default                 => ['error' => "unknown tool {$tool}"],
        };

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    protected function revenue(Shop $shop, array $input): array
    {
        [$from, $to] = PeriodResolver::resolve($input['period'] ?? 'this_month');
        return $this->aggregator->revenueSummary($shop->id, $from, $to);
    }

    protected function aggregatorFor(Shop $shop, array $input, string $method): array
    {
        [$from, $to] = PeriodResolver::resolve($input['period'] ?? 'this_month');
        return $this->aggregator->{$method}($shop->id, $from, $to);
    }

    protected function aiSummary(Shop $shop, array $input): array
    {
        [$from, $to] = \App\Support\Assistant\PeriodResolver::resolve($input['period'] ?? 'this_month');
        return $this->writer->summary($shop->id, $from, $to);
    }

    protected function bookings(Shop $shop, array $input): array
    {
        $q = DB::table('bookings')->where('shop_id', $shop->id);

        if (!empty($input['date'])) {
            $q->whereDate('date', $input['date']);
        } else {
            [$from, $to] = PeriodResolver::resolve($input['period'] ?? 'this_month');
            $q->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        }
        if (!empty($input['status'])) {
            $q->where('status', $input['status']);
        }

        $count = (clone $q)->count();
        $rows = $q->orderBy('date')->orderBy('start_time')->limit(8)->get();

        return [
            'count' => $count,
            'shown' => $rows->count(),
            'bookings' => $rows->map(fn ($b) => [
                'reference' => $b->booking_reference,
                'date' => $b->date,
                'time' => substr((string) $b->start_time, 0, 5),
                'customer' => $b->customer_name,
                'status' => $b->status,
                'charges' => (float) $b->charges,
            ])->all(),
        ];
    }

    protected function cancelBooking(Shop $shop, array $input): array
    {
        $n = DB::table('bookings')
            ->where('shop_id', $shop->id)
            ->where('booking_reference', $input['reference'] ?? '')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
        return $n ? ['cancelled' => true, 'reference' => $input['reference']]
                  : ['error' => 'No booking with that reference in your shop.'];
    }

    protected function updateStatus(Shop $shop, array $input): array
    {
        $n = DB::table('bookings')
            ->where('shop_id', $shop->id)
            ->where('booking_reference', $input['reference'] ?? '')
            ->update(['status' => $input['status'], 'updated_at' => now()]);
        return $n ? ['updated' => true, 'reference' => $input['reference'], 'status' => $input['status']]
                  : ['error' => 'No booking with that reference in your shop.'];
    }

    protected function updateHours(Shop $shop, array $input): array
    {
        $day = (int) $input['day_of_week'];
        $base = DB::table('shop_working_hours')->where('shop_id', $shop->id)->where('day_of_week', $day);
        $payload = [
            'start_time' => $input['start_time'] . ':00',
            'end_time'   => $input['end_time'] . ':00',
            'updated_at' => now(),
        ];
        if ($base->exists()) {
            (clone $base)->update($payload);
        } else {
            DB::table('shop_working_hours')->insert(array_merge($payload, [
                'shop_id' => $shop->id, 'day_of_week' => $day, 'slot_duration' => 30, 'created_at' => now(),
            ]));
        }
        return ['updated' => true, 'day_of_week' => $day];
    }

    protected function updatePrice(Shop $shop, array $input): array
    {
        $q = DB::table('catalogs')->where('shop_id', $shop->id);
        if (!empty($input['catalog_id'])) {
            $q->where('id', (int) $input['catalog_id']);
        } elseif (!empty($input['service_title'])) {
            $q->where('title', $input['service_title']);
        } else {
            return ['error' => 'Tell me which service to reprice.'];
        }
        $n = $q->update(['price' => $input['price'], 'updated_at' => now()]);
        return $n ? ['updated' => true, 'price' => $input['price']]
                  : ['error' => 'No matching service in your shop.'];
    }
}
