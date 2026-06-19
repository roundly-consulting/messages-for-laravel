<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Messages\Actions\PruneMessages;
use RoundlyConsulting\Messages\DataTransferObjects\PruneMessagesData;

final class PruneMessagesCommand extends Command
{
    protected $signature = 'messages:prune {--days= : Delete messages older than this many days} {--thread= : Limit pruning to a single thread id}';

    protected $description = 'Permanently delete messages older than the retention window';

    public function handle(PruneMessages $pruneMessages): int
    {
        $days = $this->option('days');
        $days = is_numeric($days) ? (int) $days : (int) config('messages.prune.days', 90);

        $thread = $this->option('thread');
        $threadId = is_string($thread) && $thread !== '' ? $thread : null;

        $deleted = $pruneMessages->execute(new PruneMessagesData(
            days: $days,
            threadId: $threadId,
        ));

        $this->info("Pruned {$deleted} message(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
