<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffTimeOff extends Model
{
    protected $table = 'staff_time_off';

    protected $guarded = [];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
