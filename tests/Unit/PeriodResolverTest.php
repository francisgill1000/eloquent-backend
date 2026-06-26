<?php
namespace Tests\Unit;

use App\Support\Assistant\PeriodResolver;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PeriodResolverTest extends TestCase
{
    public function test_this_month_spans_the_calendar_month(): void
    {
        $now = Carbon::parse('2026-06-27 12:00:00');
        [$from, $to] = PeriodResolver::resolve('this_month', $now);
        $this->assertSame('2026-06-01 00:00:00', $from->toDateTimeString());
        $this->assertSame('2026-06-30 23:59:59', $to->toDateTimeString());
    }

    public function test_today_spans_one_day(): void
    {
        $now = Carbon::parse('2026-06-27 12:00:00');
        [$from, $to] = PeriodResolver::resolve('today', $now);
        $this->assertSame('2026-06-27 00:00:00', $from->toDateTimeString());
        $this->assertSame('2026-06-27 23:59:59', $to->toDateTimeString());
    }

    public function test_unknown_period_defaults_to_this_month(): void
    {
        $now = Carbon::parse('2026-06-27 12:00:00');
        [$from, $to] = PeriodResolver::resolve('garbage', $now);
        $this->assertSame('2026-06-01 00:00:00', $from->toDateTimeString());
    }
}
