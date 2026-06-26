<?php
namespace App\Services\Assistant;

use App\Models\Shop;
use App\Services\Reports\ReportsAggregator;
use App\Support\Assistant\PeriodResolver;
use Illuminate\Support\Facades\DB;

/**
 * Tools the owner voice assistant can call. Every method is scoped to the
 * passed-in $shop — the controller passes the authenticated shop, so cross-shop
 * access is impossible. defs() returns Anthropic tool schemas; execute() runs
 * one tool and returns a JSON string for the tool-result message.
 */
class OwnerAssistantTools
{
    public function __construct(protected ReportsAggregator $aggregator) {}

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
                'name' => 'get_bookings',
                'description' => 'List bookings for a specific date OR a period, optionally filtered by status. Returns a count and up to 8 bookings.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'date' => ['type' => 'string', 'description' => 'A single date, YYYY-MM-DD. Optional.'],
                    'period' => $period,
                    'status' => ['type' => 'string', 'enum' => ['booked', 'completed', 'cancelled', 'queued']],
                ]],
            ],
        ];
    }

    public function execute(Shop $shop, string $tool, array $input): string
    {
        $result = match ($tool) {
            'get_revenue'           => $this->revenue($shop, $input),
            'get_top_services'      => $this->aggregatorFor($shop, $input, 'servicesSummary'),
            'get_staff_performance' => $this->aggregatorFor($shop, $input, 'staffSummary'),
            'get_busy_times'        => $this->aggregatorFor($shop, $input, 'timePatternsSummary'),
            'get_bookings'          => $this->bookings($shop, $input),
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
}
