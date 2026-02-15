<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'date',
        'start_time',
        'end_time',
        'status',
        'device_id',
        'charges',
        'services',
        'booking_reference'
    ];

    // Cast date fields
    protected $casts = [
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'services' => 'array',
    ];

    protected $appends = [
        'show_date',
    ];

    /**
     * Boot the model
     */
    protected static function booted()
    {
        static::creating(function ($booking) {
            $booking->booking_reference = self::generateBookingReference();
        });
    }

    /**
     * Generate unique booking reference number in format BK00011
     */
    protected static function generateBookingReference(): string
    {
        // BK + 5-digit zero-padded ID
        $lastId = self::latest('id')->first();
        $nextId = ($lastId ? $lastId->id : 0) + 1;
        return 'BK' . str_pad((string) $nextId, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Booking belongs to a shop
     */
    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get slot as formatted string (09:00)
     */
    public function getSlotAttribute()
    {
        return Carbon::parse($this->start_time)->format('H:i');
    }

    public function getShowDateAttribute()
    {
        return Carbon::parse($this->date)->format('d M Y');
    }

    public function getStatusAttribute($value)
    {
        return Str::title($value);
    }

    public static function ensureSlotIsAvailableOrFail(
        int $shopId,
        string $date,
        string $startTime
    ): void {
        $exists = self::where('shop_id', $shopId)
            ->where('date', $date)
            ->where('start_time', $startTime)
            ->exists();

        if ($exists) {
            throw new HttpException(
                409,
                'Slot already booked'
            );
        }
    }
}
