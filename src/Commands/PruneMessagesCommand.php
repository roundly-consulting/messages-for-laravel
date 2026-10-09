<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\MessagesConfig;
use RoundlyConsulting\Messages\Support\ThreadModel;

final class PruneMessagesCommand extends Command
{
    protected $signature = 'messages:prune {--days= : Delete messages older than this many days} {--thread= : Limit pruning to a single thread id}';

    protected $description = 'Permanently delete messages older than the retention window';

    /**
     * Through the manager, like every other path, so `Messages::fake()` records a console or
     * scheduled prune and host overrides of the action apply.
     */
    public function handle(MessagesManager $messages): int
    {
        $option = $this->option('days');

        // A whole number of at least 1, or nothing (the configured window). `--days=0` / `-1`
        // put the cutoff at or after now — every message — and `ten` / `1.9` used to read as the
        // default / 1 without a word.
        $days = $option === null
            ? MessagesConfig::pruneDays()
            : filter_var($option, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($days === false) {
            $given = is_scalar($option) ? (string) $option : get_debug_type($option);

            $this->error("The --days option must be a whole number of at least 1, [{$given}] given.");

            return self::FAILURE;
        }

        // A thread id is a string off the CLI but an int when the key type is bigint and the
        // command is called programmatically (`Artisan::call`, a test). `is_string()` alone
        // silently dropped the filter and pruned EVERY thread.
        $threadOption = $this->option('thread');
        $threadId = match (true) {
            is_string($threadOption) && $threadOption !== '' => $threadOption,
            is_int($threadOption) => $threadOption,
            default => null,
        };

        $thread = null;

        if ($threadId !== null) {
            // With the trashed ones: a soft-deleted thread's messages are still prunable. An id
            // that names no thread is refused — a null thread would prune every thread.
            $thread = ThreadModel::class()::query()->withTrashed()->find($threadId);

            if (! $thread instanceof Thread) {
                $this->error("No thread with id [{$threadId}] exists.");

                return self::FAILURE;
            }
        }

        $deleted = $messages->prune($days, $thread);

        $this->info("Pruned {$deleted} message(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
