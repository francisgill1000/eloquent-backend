<?php
namespace App\Services\Assistant;

use App\Models\AssistantToolCall;
use Illuminate\Support\Facades\Log;

/**
 * Records what the assistant actually did. Request-scoped: it remembers the
 * rows written during this turn so they can be tied to the conversation, which
 * the controller only creates after a successful reply.
 *
 * Its whole value is answering "the assistant said it did X — did it?". A turn
 * whose reply claims a change but has no `applied` row is a change the model
 * never made. That question previously needed database sequence forensics.
 */
class AssistantCallLog
{
    /** Payloads are evidence, not a mirror — some results are long list dumps. */
    private const MAX_JSON = 2000;

    private ?int $conversationId = null;

    /** @var array<int, int> ids written this request, for the conversation backfill */
    private array $written = [];

    /** The thread this request's calls belong to; null on a brand-new chat. */
    public function forConversation(?int $id): void
    {
        $this->conversationId = $id;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $result
     */
    public function record(int $shopId, string $tool, array $input, array $result, bool $userConfirmed, int $durationMs): void
    {
        $row = AssistantToolCall::create([
            'shop_id' => $shopId,
            'conversation_id' => $this->conversationId,
            'shop_user_id' => current_shop_user()?->id,
            'tool' => $tool,
            'input' => $this->cap($input),
            'result' => $this->cap($result),
            'outcome' => $this->outcome($result),
            'user_confirmed' => $userConfirmed,
            'duration_ms' => $durationMs,
        ]);

        $this->written[] = $row->id;
    }

    /**
     * Tie this request's rows to the thread once it exists. A turn may make
     * several tool calls, so this covers every row it wrote — unlike a pending
     * action, which is backfilled one row by id.
     */
    public function backfillConversation(int $id): void
    {
        if ($this->written === []) {
            return;
        }

        AssistantToolCall::whereIn('id', $this->written)
            ->whereNull('conversation_id')
            ->update(['conversation_id' => $id]);
    }

    /** Filterable shape of the result, so investigations need no JSON parsing. */
    private function outcome(array $result): string
    {
        return match (true) {
            isset($result['done']) => 'applied',
            isset($result['preview']) => 'preview',
            isset($result['error']) => 'error',
            default => 'read',
        };
    }

    /**
     * Keep the stored payload under MAX_JSON characters. Drops whole values,
     * biggest first, rather than cutting the JSON mid-string — a truncated row
     * still has to decode.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function cap(array $data): array
    {
        if (strlen((string) json_encode($data)) <= self::MAX_JSON) {
            return $data;
        }

        foreach ($data as $key => $value) {
            $data[$key] = is_string($value)
                ? mb_substr($value, 0, 200) . '…[truncated]'
                : '…[truncated]';
            if (strlen((string) json_encode($data)) <= self::MAX_JSON) {
                return $data;
            }
        }

        return ['_truncated' => true];
    }

    /**
     * Never let logging break a tool call. Used by the registry so a failure
     * here costs evidence, not the owner's change.
     */
    public function recordSafely(int $shopId, string $tool, array $input, array $result, bool $userConfirmed, int $durationMs): void
    {
        try {
            $this->record($shopId, $tool, $input, $result, $userConfirmed, $durationMs);
        } catch (\Throwable $e) {
            Log::warning('assistant tool-call logging failed: ' . $e->getMessage());
        }
    }
}
