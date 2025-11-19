<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deal extends Model
{
    const STATUS_OPEN = 'Open';
    const STATUS_NEGOTIATION = 'Negotiation';
    const STATUS_WON = 'Closed-Won';
    const STATUS_LOST = 'Closed-Lost';

    protected $fillable = [
        'lead_id',
        'customer_id',
        'agent_id',
        'deal_title',
        'amount',
        'discount',
        'tax',
        'total',
        'status',
        'expected_close_date',
    ];

    public static function statuses()
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_NEGOTIATION,
            self::STATUS_WON,
            self::STATUS_LOST,
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
