<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaAccount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'token' => 'encrypted',
    ];

    protected $hidden = [
        'token',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function contacts()
    {
        return $this->hasMany(WaContact::class);
    }
}
