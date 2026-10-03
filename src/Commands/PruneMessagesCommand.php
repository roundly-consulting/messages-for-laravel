<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Messages\Actions\PruneMessages;
use RoundlyConsulting\Messages\DataTransferObjects\PruneMessagesData;
use RoundlyConsulting\Messages\Support\MessagesConfig;

final class PruneMessagesCommand extends Command
{
    protected $signature = 'messages:prune {--days= : Delete messages older than this many days} {--thread= : Limit pruning to a single thread id}';

    protected $description = 'Permanently delete messages older than the retention window';

    public function handle(PruneMessages $pruneMessages): int
    {
        $days = $this->option('days');
        $days = is_numeric($days) ? (int) $days : MessagesConfig::pruneDays();

        // A thread id is a string off the CLI but an int when the key type is bigint and the
        // command is called programmatically (`Artisan::call`, a test). `is_string()` alone
        // silently dropped the filter and pruned EVERY thread.
        $thread = $this->option('thread');
        $threadId = match (true) {
            is_string($thread) && $thread !== '' => $thread,
            is_int($thread) => $thread,
            default => null,
        };

        $deleted = $pruneMessages->execute(new PruneMessagesData(
            days: $days,
            threadId: $threadId,
        ));

        $this->info("Pruned {$deleted} message(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
