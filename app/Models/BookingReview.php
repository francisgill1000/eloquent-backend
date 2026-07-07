<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BookingReview extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rating' => 'integer',
        'review_request_sent_at' => 'datetime',
        'rated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (BookingReview $review) {
            $review->token = $review->token ?: Str::random(48);
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function shopCustomer(): BelongsTo
    {
        return $this->belongsTo(ShopCustomer::class);
    }
}
