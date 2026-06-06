<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class WaMessage extends Model
{
    protected $guarded = [];

    protected $appends = ['media_url'];

    public function waContact()
    {
        return $this->belongsTo(WaContact::class);
    }

    public function getMediaUrlAttribute(): ?string
    {
        return $this->media_path ? Storage::disk('public')->url($this->media_path) : null;
    }
}
