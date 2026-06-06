<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaContact extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function waAccount()
    {
        return $this->belongsTo(WaAccount::class);
    }

    public function messages()
    {
        return $this->hasMany(WaMessage::class);
    }

    /**
     * Record a new message on this contact's thread and refresh list metadata.
     */
    public function recordMessage(string $direction, string $body, string $type = 'text', ?string $waMessageId = null, ?string $status = null, array $media = []): WaMessage
    {
        $message = $this->messages()->create([
            'wa_account_id' => $this->wa_account_id,
            'direction' => $direction,
            'type' => $type,
            'body' => $body,
            'wa_message_id' => $waMessageId,
            'status' => $status,
            'media_id' => $media['media_id'] ?? null,
            'media_mime' => $media['media_mime'] ?? null,
            'media_path' => $media['media_path'] ?? null,
        ]);

        $this->forceFill([
            'last_message_preview' => mb_substr($body, 0, 500),
            'last_message_direction' => $direction,
            'last_message_at' => now(),
            // ?? 0: a just-created model hasn't hydrated the DB default yet
            'unread_count' => ($this->unread_count ?? 0) + ($direction === 'in' ? 1 : 0),
        ])->save();

        return $message;
    }
}
