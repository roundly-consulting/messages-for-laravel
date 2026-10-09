<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Fixtures;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;

/**
 * Records what a flow says to the database, in order: every query (normalised — lowercase, no
 * identifier quotes) with the transaction depth it ran at, plus a `#commit` entry each time a
 * transaction level commits.
 *
 * It exists for the guards a single connection cannot replay as a race: one connection never
 * blocks on its own lock, so an in-process interleaving stays red with or without the lock.
 * What can be shown is the order — the thread row is locked, inside a transaction that has not
 * committed yet, before the read that the lock is meant to protect.
 *
 * SQLite compiles `lockForUpdate()` to nothing, so on SQLite the recording grammar from
 * testing-for-laravel is installed first and a lock shows up as a trailing marker; Postgres and
 * MySQL print `for update` / `for share` themselves.
 */
final class QueryRecorder
{
    /**
     * @return list<array{sql: string, depth: int}>
     */
    public static function during(Closure $flow): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            $connection->setQueryGrammar(new LockRecordingGrammar($connection));
        }

        $log = [];
        $recording = true;

        DB::listen(static function (QueryExecuted $query) use (&$log, &$recording): void {
            if ($recording) {
                $log[] = [
                    'sql' => str_replace(['"', '`'], '', strtolower($query->sql)),
                    'depth' => $query->connection->transactionLevel(),
                ];
            }
        });

        Event::listen(TransactionCommitted::class, static function (TransactionCommitted $event) use (&$log, &$recording): void {
            if ($recording) {
                $log[] = ['sql' => '#commit', 'depth' => $event->connection->transactionLevel()];
            }
        });

        try {
            $flow();
        } finally {
            $recording = false;
        }

        return $log;
    }

    /**
     * The position of the first entry satisfying `$matches`, or null.
     *
     * @param  list<array{sql: string, depth: int}>  $log
     * @param  Closure(string): bool  $matches
     */
    public static function first(array $log, Closure $matches): ?int
    {
        foreach ($log as $index => $entry) {
            if ($matches($entry['sql'])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Whether a `for update` lock on a row of `$table` is still held when entry `$at` runs: taken
     * earlier, inside a transaction, and with no commit back to depth 0 in between (a savepoint
     * release keeps row locks; only the outermost commit gives them up).
     *
     * @param  list<array{sql: string, depth: int}>  $log
     */
    public static function lockHeldAt(array $log, int $at, string $table): bool
    {
        $held = false;

        foreach (array_slice($log, 0, $at) as $entry) {
            if ($entry['sql'] === '#commit' && $entry['depth'] === 0) {
                $held = false;
            } elseif ($entry['depth'] >= 1 && self::locksForUpdate($entry['sql']) && str_contains($entry['sql'], 'from '.$table.' ')) {
                $held = true;
            }
        }

        return $held;
    }

    public static function locksForUpdate(string $sql): bool
    {
        return str_contains($sql, '/* lock-for-update */') || str_ends_with($sql, ' for update');
    }

    public static function locksShared(string $sql): bool
    {
        return str_contains($sql, '/* lock-shared */')
            || str_ends_with($sql, ' for share')
            || str_ends_with($sql, ' lock in share mode');
    }
}
