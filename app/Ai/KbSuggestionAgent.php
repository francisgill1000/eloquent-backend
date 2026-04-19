<?php

namespace App\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class KbSuggestionAgent implements Agent
{
    use Promptable;

    public function provider(): string
    {
        return 'openai';
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
You are an offline curator helping build a FAQ knowledge base for the Rezzy app.
You receive a list of real user messages and cluster them into reusable KB entries.
Always respond with a raw JSON array — never include markdown, code fences, or explanations.
Each array item must contain: id (snake_case), patterns (array of PHP PCRE regexes with delimiters),
answer (short, friendly, accurate), and reason (one-line rationale).
PROMPT;
    }
}
