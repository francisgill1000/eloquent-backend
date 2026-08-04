<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A previewed-but-unwritten assistant tool call, held until the owner taps
 * Confirm in the chat. The SPA only ever receives the id — confirming
 * re-executes from `input` here, so the values written are the values shown.
 */
class AssistantPendingAction extends Model
{
    protected $fillable = [
        'shop_id', 'conversation_id', 'tool', 'input', 'summary',
        'changes', 'destructive', 'resolved_at', 'expires_at',
    ];

    protected $casts = [
        'input' => 'array',
        'changes' => 'array',
        'destructive' => 'bool',
        'resolved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** Still confirmable: neither already applied nor timed out. */
    public function isLive(): bool
    {
        return $this->resolved_at === null && $this->expires_at->isFuture();
    }

    /** Live rows for one shop + tool — used to resolve a card the model self-confirmed. */
    public function scopeOpen(Builder $q, int $shopId, string $tool): Builder
    {
        return $q->where('shop_id', $shopId)
            ->where('tool', $tool)
            ->whereNull('resolved_at')
            ->where('expires_at', '>', now());
    }
}
