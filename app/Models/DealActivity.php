<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'deal_id',
        'user_id',
        'contact_method',
        'follow_up_date',
        'note',
    ];

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
