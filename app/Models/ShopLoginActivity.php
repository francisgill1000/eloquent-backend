<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopLoginActivity extends Model
{
    use HasFactory;

    const METHOD_PIN = 'pin';
    const METHOD_QR = 'qr';
    const METHOD_AUTO = 'auto';

    protected $guarded = [];

    protected $casts = [
        'logged_in_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
