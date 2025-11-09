<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'agent_id',
        'source',
        'status',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class);
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class);
    }
}
