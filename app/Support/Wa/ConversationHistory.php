<?php

namespace App\Support\Wa;

use App\Models\WaContact;

/**
 * Build the Claude conversation history for a contact from stored
 * wa_messages. Replaces the Node service's in-memory store — survives
 * restarts and is shared with the bizrezzy chat threads.
 */
class ConversationHistory
{
    public const LIMIT = 10; // turns kept per thread (Node parity)

    /** @return array<int, array{role: string, content: string}> */
    public static function for(WaContact $contact, int $limit = self::LIMIT): array
    {
        // Fetch extra rows: placeholders get skipped and turns get merged.
        $messages = $contact->messages()
            ->orderByDesc('id')
            ->limit($limit * 3)
            ->get(['id', 'direction', 'body'])
            ->reverse()
            ->values();

        $turns = [];
        foreach ($messages as $message) {
            $body = trim(preg_replace('/^(🎤|🔊)\s*/u', '', (string) $message->body));
            if ($body === '' || preg_match('/^\[\w+( \w+)? message\]$/i', $body)) {
                continue; // media placeholder like "[image message]"
            }

            $role = $message->direction === 'in' ? 'user' : 'assistant';
            if ($turns && $turns[count($turns) - 1]['role'] === $role) {
                // Claude requires alternating roles — merge consecutive turns.
                $turns[count($turns) - 1]['content'] .= "\n" . $body;
            } else {
                $turns[] = ['role' => $role, 'content' => $body];
            }
        }

        $turns = array_slice($turns, -$limit);

        // Claude requires the first turn to be from the user.
        while ($turns && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        return $turns;
    }
}
