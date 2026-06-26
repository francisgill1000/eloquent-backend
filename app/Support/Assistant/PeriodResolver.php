<?php
namespace App\Support\Assistant;

use Illuminate\Support\Carbon;

/**
 * Maps a spoken period word ("this month", normalized by the model to
 * "this_month") to a concrete [from, to] date range for the reporting tools.
 */
class PeriodResolver
{
    /** @return array{0: Carbon, 1: Carbon} */
    public static function resolve(string $period, ?Carbon $now = null): array
    {
        $now = $now ? $now->copy() : Carbon::now();
        return match ($period) {
            'today'      => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday'  => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'this_week'  => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_year'  => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default      => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }
}
