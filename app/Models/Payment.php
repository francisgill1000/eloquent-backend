<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = [];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, "model_id")->where("model", "Invoice");
    }
}
