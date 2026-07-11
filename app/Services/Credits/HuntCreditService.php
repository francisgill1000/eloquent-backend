<?php

namespace App\Services\Credits;

use App\Models\HuntCreditTransaction;
use App\Models\Shop;
use App\Services\Credits\Exceptions\InsufficientCredits;
use Illuminate\Support\Facades\DB;

/**
 * The single authority for Business Hunt credits. Every movement goes through
 * apply(): it locks the shop row, moves the cached balance (shops.hunt_credits)
 * and appends a ledger row (hunt_credit_transactions) in ONE transaction, so
 * concurrent live searches can never oversell or drive the balance negative.
 *
 * Strictly per-shop and completely independent of the Lens subscription meter.
 */
class HuntCreditService
{
    /** Current credit balance for a shop. */
    public function balance(Shop $shop): int
    {
        return (int) ($shop->hunt_credits ?? 0);
    }

    /** Add credits (master grant, pack purchase, or a refund). */
    public function grant(Shop $shop, int $amount, string $reason = 'grant', array $meta = []): HuntCreditTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Grant amount must be positive.');
        }

        return $this->apply($shop, $amount, $reason, $meta);
    }

    /**
     * Remove credits (one per live search). Throws InsufficientCredits — leaving
     * the balance and ledger untouched — when the shop can't cover it.
     */
    public function debit(Shop $shop, int $amount, string $reason = 'search', array $meta = []): HuntCreditTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Debit amount must be positive.');
        }

        return $this->apply($shop, -$amount, $reason, $meta);
    }

    private function apply(Shop $shop, int $delta, string $reason, array $meta): HuntCreditTransaction
    {
        return DB::transaction(function () use ($shop, $delta, $reason, $meta) {
            // Row lock: serialize concurrent movements for this one shop so the
            // read-modify-write below is atomic (no oversell on parallel searches).
            $locked = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();

            $new = (int) $locked->hunt_credits + $delta;
            if ($new < 0) {
                throw new InsufficientCredits((int) $locked->hunt_credits, -$delta);
            }

            $locked->forceFill(['hunt_credits' => $new])->saveQuietly();
            // Keep the caller's instance consistent with what we just persisted.
            $shop->setAttribute('hunt_credits', $new);

            return HuntCreditTransaction::create([
                'shop_id' => $shop->id,
                'amount' => $delta,
                'reason' => $reason,
                'balance_after' => $new,
                'meta' => $meta !== [] ? $meta : null,
            ]);
        });
    }
}
