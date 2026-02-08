<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuestFavourite extends Model
{
    protected $fillable = [
        'device_id',
        'shop_id',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
