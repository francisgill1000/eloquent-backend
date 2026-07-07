<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = ['shop_id', 'title'];

    protected static function booted(): void
    {
        // Deleting a thread must delete its messages one-by-one so each
        // AssistantMessage's deleting hook removes its audio file.
        static::deleting(function (Conversation $c) {
            $c->messages()->get()->each->delete();
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
