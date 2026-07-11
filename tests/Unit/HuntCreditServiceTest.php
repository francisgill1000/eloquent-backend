<?php

namespace Tests\Unit;

use App\Models\HuntCreditTransaction;
use App\Models\Shop;
use App\Services\Credits\Exceptions\InsufficientCredits;
use App\Services\Credits\HuntCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HuntCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): HuntCreditService
    {
        return app(HuntCreditService::class);
    }

    private function shop(string $code): Shop
    {
        return Shop::create(['name' => 'S' . $code, 'shop_code' => $code, 'status' => 'active']);
    }

    public function test_new_shop_starts_at_zero(): void
    {
        $this->assertSame(0, $this->service()->balance($this->shop('810001')));
    }

    public function test_grant_increases_balance_and_writes_a_ledger_row(): void
    {
        $shop = $this->shop('810002');

        $tx = $this->service()->grant($shop, 200, 'grant', ['note' => 'ziina']);

        $this->assertSame(200, $this->service()->balance($shop->fresh()));
        $this->assertSame(200, $tx->balance_after);
        $this->assertSame(200, $tx->amount);
        $this->assertSame('grant', $tx->reason);
        $this->assertSame('ziina', $tx->meta['note']);
        $this->assertDatabaseHas('hunt_credit_transactions', [
            'shop_id' => $shop->id, 'amount' => 200, 'reason' => 'grant', 'balance_after' => 200,
        ]);
    }

    public function test_debit_decreases_balance(): void
    {
        $shop = $this->shop('810003');
        $this->service()->grant($shop, 5);

        $tx = $this->service()->debit($shop, 1, 'search', ['query' => 'salon']);

        $this->assertSame(4, $this->service()->balance($shop->fresh()));
        $this->assertSame(-1, $tx->amount);
        $this->assertSame(4, $tx->balance_after);
        $this->assertSame('search', $tx->reason);
    }

    public function test_debit_beyond_balance_throws_and_changes_nothing(): void
    {
        $shop = $this->shop('810004');
        $this->service()->grant($shop, 1);

        $this->service()->debit($shop, 1); // ok, now 0

        try {
            $this->service()->debit($shop, 1);
            $this->fail('Expected InsufficientCredits.');
        } catch (InsufficientCredits $e) {
            $this->assertSame(0, $e->balance);
            $this->assertSame(1, $e->required);
        }

        $this->assertSame(0, $this->service()->balance($shop->fresh()));
        // The grant + the one successful debit = 2 rows; the failed debit wrote none.
        $this->assertSame(2, HuntCreditTransaction::where('shop_id', $shop->id)->count());
    }

    public function test_balances_are_scoped_per_shop(): void
    {
        $a = $this->shop('810005');
        $b = $this->shop('810006');

        $this->service()->grant($a, 300);

        $this->assertSame(300, $this->service()->balance($a->fresh()));
        $this->assertSame(0, $this->service()->balance($b->fresh()));
        $this->assertDatabaseMissing('hunt_credit_transactions', ['shop_id' => $b->id]);
    }

    public function test_grant_amount_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->grant($this->shop('810007'), 0);
    }
}
