<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pricing extends Model
{
    protected $table = 'pricing';

    protected $fillable = ['plan', 'price_fils'];

    public static function fils(string $plan): int
    {
        return (int) (static::where('plan', $plan)->value('price_fils') ?? 0);
    }
}
