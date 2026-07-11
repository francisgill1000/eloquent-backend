<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A master-editable Business Hunt credit pack. `price_fils` is in fils
 * (100 = AED 1), mirroring the Pricing model's convention.
 */
class CreditPack extends Model
{
    protected $guarded = [];

    protected $casts = [
        'credits' => 'integer',
        'price_fils' => 'integer',
        'active' => 'boolean',
        'sort' => 'integer',
    ];
}
