<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaMessage extends Model
{
    protected $guarded = [];

    public function waContact()
    {
        return $this->belongsTo(WaContact::class);
    }
}
