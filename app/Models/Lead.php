<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    const STATUS_NEW = 'New';
    const STATUS_CONTACTED = 'Contacted';
    const STATUS_INTERESTED = 'Interested';
    const STATUS_CONVERTED = 'Converted';
    const STATUS_LOST = 'Lost';

    public static function statuses()
    {
        return [
            self::STATUS_NEW,
            self::STATUS_CONTACTED,
            self::STATUS_INTERESTED,
            self::STATUS_CONVERTED,
            self::STATUS_LOST,
        ];
    }

    use HasFactory;

    protected $fillable = [
        'customer_id',
        'agent_id',
        'source',
        'status', // Add statuses: New, Contacted, Qualified, In Progress, Closed-Won, Closed-Lost.
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class);
    }
}
