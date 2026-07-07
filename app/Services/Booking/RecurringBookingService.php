<?php

namespace App\Services\Booking;

use App\Models\Shop;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Materialises a recurring appointment into concrete bookings (one per
 * occurrence) so every existing feature — calendar, reminders, reviews,
 * invoices, staff assignment — works with zero special-casing. Each occurrence
 * runs through BookingCreator, so a busy date is queued exactly like a one-off.
 */
class RecurringBookingService
{
    public function __construct(private BookingCreator $creator)
    {
    }

    /**
     * @param  array   $base        normal book payload (date, start_time, services, ...)
     * @param  string  $frequency   weekly|biweekly|monthly
     * @param  int     $occurrences 2..52
     * @return array{series_id:string, created:array, skipped:array}
     */
    public function createSeries(Shop $shop, array $base, string $frequency, int $occurrences): array
    {
        $seriesId = (string) Str::random(32);
        $start = Carbon::parse($base['date']);

        $created = [];
        $skipped = [];

        for ($i = 0; $i < $occurrences; $i++) {
            $date = $this->advance($start, $frequency, $i)->toDateString();

            try {
                $booking = $this->creator->create($shop, array_merge($base, [
                    'date'                => $date,
                    'recurring_series_id' => $seriesId,
                ]));
                $created[] = $booking;
            } catch (HttpException $e) {
                // e.g. shop closed that weekday — skip this occurrence, keep the series.
                $skipped[] = ['date' => $date, 'reason' => $e->getMessage()];
            }
        }

        return ['series_id' => $seriesId, 'created' => $created, 'skipped' => $skipped];
    }

    private function advance(Carbon $start, string $frequency, int $i): Carbon
    {
        return match ($frequency) {
            'weekly'   => $start->copy()->addWeeks($i),
            'biweekly' => $start->copy()->addWeeks($i * 2),
            'monthly'  => $start->copy()->addMonthsNoOverflow($i),
            default    => $start->copy()->addWeeks($i),
        };
    }
}
