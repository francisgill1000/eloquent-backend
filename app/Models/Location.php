<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

class Location extends Model
{
    protected $fillable = ['lat', 'lon', 'address'];
}
