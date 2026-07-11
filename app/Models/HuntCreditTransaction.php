<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row in the Business Hunt credit ledger. Append-only — never updated or
 * deleted in normal operation. `amount` is signed; `balance_after` snapshots the
 * shop's balance right after this movement.
 */
class HuntCreditTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
        'meta' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
