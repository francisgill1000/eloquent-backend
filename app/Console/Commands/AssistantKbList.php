<?php

namespace App\Console\Commands;

use App\Models\AiKbEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AssistantKbList extends Command
{
    protected $signature = 'assistant:kb-list
        {--pending : Show only pending suggestions (source=suggested and disabled)}
        {--source= : Filter by source (seeded|suggested|manual)}
        {--enabled= : Filter by enabled flag (1 or 0)}';

    protected $description = 'List KB entries, optionally filtered to pending suggestions.';

    public function handle(): int
    {
        $q = AiKbEntry::query()->orderBy('enabled')->orderBy('priority')->orderBy('kb_id');

        if ($this->option('pending')) {
            $q->where('source', 'suggested')->where('enabled', false);
        }

        if ($src = $this->option('source')) {
            $q->where('source', $src);
        }

        $enabled = $this->option('enabled');
        if ($enabled !== null && $enabled !== '') {
            $q->where('enabled', (bool) (int) $enabled);
        }

        $rows = $q->get(['kb_id', 'source', 'enabled', 'priority', 'hit_count', 'answer']);

        if ($rows->isEmpty()) {
            $this->info('No entries match.');
            return self::SUCCESS;
        }

        $this->table(
            ['kb_id', 'source', 'enabled', 'prio', 'hits', 'answer'],
            $rows->map(fn ($r) => [
                $r->kb_id,
                $r->source,
                $r->enabled ? 'yes' : 'no',
                $r->priority,
                $r->hit_count,
                Str::limit((string) $r->answer, 80),
            ])->all()
        );

        return self::SUCCESS;
    }
}
