<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id', 'invoice_number',
        'subtotal', 'total', 'status',
        'issued_at', 'paid_at',
    ];

    protected $casts = [
        'subtotal'  => 'decimal:2',
        'total'     => 'decimal:2',
        'issued_at' => 'datetime',
        'paid_at'   => 'datetime',
    ];

    protected static function booted()
    {
        static::created(function ($invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = 'INV' . str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT);
                $invoice->saveQuietly();
            }
        });
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\BookingInvoiceFactory::new();
    }
}
