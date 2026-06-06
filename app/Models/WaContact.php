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
    public function recordMessage(string $direction, string $body, string $type = 'text', ?string $waMessageId = null, ?string $status = null): WaMessage
    {
        $message = $this->messages()->create([
            'wa_account_id' => $this->wa_account_id,
            'direction' => $direction,
            'type' => $type,
            'body' => $body,
            'wa_message_id' => $waMessageId,
            'status' => $status,
        ]);

        $this->forceFill([
            'last_message_preview' => mb_substr($body, 0, 500),
            'last_message_direction' => $direction,
            'last_message_at' => now(),
            'unread_count' => $direction === 'in' ? $this->unread_count + 1 : $this->unread_count,
        ])->save();

        return $message;
    }
}
