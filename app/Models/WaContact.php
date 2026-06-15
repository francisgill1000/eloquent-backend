<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaContact extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_message_at' => 'datetime',
        'ai_enabled' => 'boolean',
    ];

    public function waAccount()
    {
        return $this->belongsTo(WaAccount::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    /** App-channel (in-app Live Chat) contacts have no WaAccount. */
    public function isApp(): bool
    {
        return $this->channel === 'app';
    }

    /** The shop this thread belongs to, whichever channel it came in on. */
    public function ownerShop(): ?Shop
    {
        return $this->shop_id ? $this->shop : $this->waAccount?->shop;
    }

    public function messages()
    {
        return $this->hasMany(WaMessage::class);
    }

    /**
     * Record a new message on this contact's thread and refresh list metadata.
     *
     * $senderType is who actually sent it: customer | ai | staff. When omitted
     * it's inferred — inbound is the customer, outbound is the AI. A 'staff'
     * message means a human stepped in, so the AI auto-pauses for this thread
     * (agent takeover) until staff manually hand it back.
     */
    public function recordMessage(string $direction, string $body, string $type = 'text', ?string $waMessageId = null, ?string $status = null, array $media = [], ?string $senderType = null): WaMessage
    {
        $senderType ??= $direction === 'in' ? 'customer' : 'ai';

        $message = $this->messages()->create([
            'wa_account_id' => $this->wa_account_id,
            'direction' => $direction,
            'sender_type' => $senderType,
            'type' => $type,
            'body' => $body,
            'wa_message_id' => $waMessageId,
            'status' => $status,
            'media_id' => $media['media_id'] ?? null,
            'media_mime' => $media['media_mime'] ?? null,
            'media_path' => $media['media_path'] ?? null,
        ]);

        $attributes = [
            'last_message_preview' => mb_substr($body, 0, 500),
            'last_message_direction' => $direction,
            'last_message_at' => now(),
            // ?? 0: a just-created model hasn't hydrated the DB default yet
            'unread_count' => ($this->unread_count ?? 0) + ($direction === 'in' ? 1 : 0),
        ];
        // Agent takeover: a real human replying pauses the AI for this thread.
        if ($senderType === 'staff') {
            $attributes['ai_enabled'] = false;
        }

        $this->forceFill($attributes)->save();

        return $message;
    }
}
