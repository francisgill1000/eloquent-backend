<?php

namespace App\Console\Commands;

use App\Ai\KbSuggestionAgent;
use App\Models\AiAssistantLog;
use App\Models\AiKbEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class AssistantSuggestKb extends Command
{
    protected $signature = 'assistant:suggest-kb
        {--days=7 : Look back window for unmatched messages}
        {--limit=50 : Max unmatched messages to feed the LLM}
        {--min-cluster=2 : Minimum messages per cluster to become a suggestion}';

    protected $description = 'Cluster recent unmatched user messages and ask the LLM to propose new KB entries (saved as disabled suggestions).';

    public function handle(): int
    {
        $days       = max(1, (int) $this->option('days'));
        $limit      = max(1, min(500, (int) $this->option('limit')));
        $minCluster = max(1, (int) $this->option('min-cluster'));

        $messages = AiAssistantLog::query()
            ->where('reviewed', false)
            ->where('matched', false)
            ->where('source', 'llm')
            ->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->pluck('message')
            ->filter(fn ($m) => is_string($m) && trim($m) !== '')
            ->unique()
            ->values();

        if ($messages->isEmpty()) {
            $this->info("No unmatched messages in the last {$days} day(s). Nothing to suggest.");
            return self::SUCCESS;
        }

        $this->info("Analysing {$messages->count()} unmatched message(s)...");

        try {
            $agent    = KbSuggestionAgent::make();
            $prompt   = $this->buildPrompt($messages->all(), $minCluster);
            $response = $agent->prompt($prompt);
            $raw      = trim((string) $response->text);
        } catch (Throwable $e) {
            $this->error('LLM call failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $suggestions = $this->parseSuggestions($raw);

        if ($suggestions === null) {
            $this->warn('Could not parse LLM output as JSON. Raw output:');
            $this->line($raw);
            return self::FAILURE;
        }

        if (empty($suggestions)) {
            $this->info('LLM returned no suggestions.');
            return self::SUCCESS;
        }

        $saved = 0;
        foreach ($suggestions as $s) {
            $patterns = array_values(array_filter(array_map('trim', (array) ($s['patterns'] ?? []))));
            $answer   = trim((string) ($s['answer'] ?? ''));
            $label    = trim((string) ($s['id'] ?? $s['label'] ?? ''));

            if ($answer === '' || empty($patterns)) {
                continue;
            }

            $kbId = $label !== ''
                ? 'suggested_' . Str::slug(Str::limit($label, 40, ''), '_')
                : 'suggested_' . Str::random(8);

            // Ensure uniqueness.
            $base = $kbId; $i = 1;
            while (AiKbEntry::where('kb_id', $kbId)->exists()) {
                $kbId = $base . '_' . (++$i);
            }

            AiKbEntry::create([
                'kb_id'    => $kbId,
                'patterns' => $patterns,
                'answer'   => $answer,
                'priority' => 90,
                'enabled'  => false,
                'source'   => 'suggested',
                'notes'    => $s['reason'] ?? null,
            ]);

            $saved++;
            $this->line("  + {$kbId}");
        }

        // Mark the inspected logs as reviewed so we don't re-cluster them endlessly.
        AiAssistantLog::query()
            ->where('reviewed', false)
            ->where('matched', false)
            ->where('source', 'llm')
            ->where('created_at', '>=', now()->subDays($days))
            ->update(['reviewed' => true]);

        $this->info("Saved {$saved} suggestion(s). Run `php artisan assistant:kb-list --pending` to review.");
        return self::SUCCESS;
    }

    private function buildPrompt(array $messages, int $minCluster): string
    {
        $numbered = [];
        foreach ($messages as $i => $m) {
            $numbered[] = ($i + 1) . '. ' . Str::limit((string) $m, 300, '');
        }
        $list = implode("\n", $numbered);

        return <<<PROMPT
You are helping curate a FAQ knowledge base for the Rezzy app (UAE service-booking platform, Sharjah-first).

Below are real user messages that the current KB did NOT answer. Cluster them by topic and,
for each cluster with at least {$minCluster} similar messages, propose a new KB entry.

Return ONLY a JSON array (no markdown, no prose). Each item:
{
  "id": "<short_snake_case_label>",
  "patterns": ["<php_pcre_regex>", "..."],
  "answer": "<friendly 1-3 sentence answer in English>",
  "reason": "<one-line reason this cluster deserves an entry>"
}

Rules:
- Each regex MUST be a valid PHP PCRE pattern with delimiters, e.g. "/\\bworking\\s+hours\\b/i".
- Answers must be accurate and conservative; never invent prices, names, or policies.
- Skip clusters about invoices/billing (those are deflected by design).
- Skip clusters smaller than {$minCluster} messages.
- Return an empty array [] if nothing meets the bar.

Unmatched messages:
{$list}
PROMPT;
    }

    private function parseSuggestions(string $text): ?array
    {
        if (str_contains($text, '```')) {
            $text = preg_replace('/```(?:json)?\s*|```/m', '', $text);
        }
        $text = trim($text);

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\[[\s\S]*\]/', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
