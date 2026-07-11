<?php

namespace Tests\Feature;

use App\Models\AiSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiSummaryPeriodTypeTest extends TestCase
{
    use RefreshDatabase;

    private function row(array $overrides = []): AiSummary
    {
        return AiSummary::create(array_merge([
            'shop_id' => 1, 'summary_date' => '2026-07-12',
            'period_from' => '2026-07-01', 'period_to' => '2026-07-07',
            'summary' => 's', 'patterns' => ['p'], 'recommendations' => ['r'],
            'period_type' => 'week',
        ], $overrides));
    }

    public function test_period_type_defaults_to_rolling30(): void
    {
        $r = AiSummary::create([
            'shop_id' => 1, 'summary_date' => '2026-07-12',
            'period_from' => '2026-06-13', 'period_to' => '2026-07-12',
            'summary' => 's', 'patterns' => [], 'recommendations' => [],
        ]);
        $this->assertSame('rolling30', $r->fresh()->period_type);
    }

    public function test_unique_is_scoped_by_period_type_and_window(): void
    {
        $this->row(); // week 07-01..07-07

        // Same shop + window but a DIFFERENT period_type is allowed.
        $this->row(['period_type' => 'custom']);
        $this->assertSame(2, AiSummary::count());

        // Same shop + period_type + window collides (unique) — updateOrCreate upserts.
        // Note: 'period_from'/'period_to' are date-cast, so Eloquent stores them via
        // fromDateTime() using the query grammar's date format ('Y-m-d H:i:s'), not
        // the bare 'Y-m-d' string. updateOrCreate() builds its lookup WHERE from the
        // raw values given (no cast applied on read), so the key values below must
        // match the stored format exactly or the lookup silently misses and a
        // colliding INSERT is attempted instead of an UPDATE.
        AiSummary::updateOrCreate(
            ['shop_id' => 1, 'period_type' => 'week', 'period_from' => '2026-07-01 00:00:00', 'period_to' => '2026-07-07 00:00:00'],
            ['summary_date' => '2026-07-13', 'summary' => 'updated', 'patterns' => [], 'recommendations' => []],
        );
        $this->assertSame(2, AiSummary::count());
        $this->assertSame('updated', AiSummary::where('period_type', 'week')->first()->summary);
    }
}
