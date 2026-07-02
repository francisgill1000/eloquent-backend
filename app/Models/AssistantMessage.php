<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AssistantMessage extends Model
{
    protected $fillable = ['shop_id', 'role', 'content', 'audio_path', 'audio_mime'];

    protected static function booted(): void
    {
        // Keep disk and DB in step: a deleted row must not orphan its audio file.
        static::deleting(function (AssistantMessage $m) {
            if ($m->audio_path) {
                Storage::disk('local')->delete($m->audio_path);
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
