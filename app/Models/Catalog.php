<?php

namespace App\Models;

use App\Traits\HasBase64Image;
use Illuminate\Database\Eloquent\Model;

class Catalog extends Model
{
    use HasBase64Image;

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
