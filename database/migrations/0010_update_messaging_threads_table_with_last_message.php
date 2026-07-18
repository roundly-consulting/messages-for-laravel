<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Messages\Concerns\MaintainsThreadLatestMessage;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * Denormalise the thread's newest message onto the thread.
 *
 * `latestMessage()` resolved the winner live, with an ordered `hasOne`. That is correct on
 * every engine and every key type, but it has no way to say "one row per thread" — so eager
 * loading it returned EVERY message of every thread on the page and threw all but one away.
 * Measured on Postgres: an inbox page of 25 threads holding 1,000 messages each hydrated
 * 25,000 rows to display 25, at 297ms.
 *
 * A `row_number()` window function was the other candidate. It fixes the row count but not
 * the cost: the outer `thread_id in (...)` filter cannot be pushed into the window subquery,
 * so Postgres ranks the WHOLE table to answer one page — measured scanning all 100,000 rows
 * (and spilling the sort to disk without an index) for 25 results, 13.9ms even with an ideal
 * index, and growing with the table forever. This column is a plain indexed lookup instead:
 * 25 index searches, 0.09ms, and its cost tracks the page rather than the table.
 *
 * The trade is that a stored answer is only as correct as the code maintaining it, so
 * {@see MaintainsThreadLatestMessage} recomputes it on
 * every path that can change which message is newest.
 */
return new class extends Migration
{
    public function up(): void
    {
        $keyType = KeyType::fromConfig('messages.primary_key_type');

        Schema::table('messaging_threads', function (Blueprint $table) use ($keyType): void {
            if (! Schema::hasColumn('messaging_threads', 'last_message_id')) {
                // Points at messaging_messages.id and tracks the messages PK type — but takes
                // no FK constraint, matching `last_read_message_id` (0006). A constraint here
                // would be circular: messaging_messages.thread_id already references
                // messaging_threads with ON DELETE CASCADE, so a thread and the message it
                // points at would each depend on the other's deletion order.
                $table->ownerKey('last_message_id', $keyType, nullable: true, index: false)
                    ->after('last_activity_at');
            }
        });

        $this->backfill();
    }

    /**
     * Point every existing thread at its newest message, applying exactly the order the
     * relation used to compute live: newest timestamp first, key descending to break the
     * ties that second-precision timestamps make routine.
     *
     * Chunked and expressed through the query builder rather than a correlated raw UPDATE:
     * the three supported engines disagree about update-with-subquery syntax, and this
     * package ships no raw SQL.
     */
    private function backfill(): void
    {
        DB::table('messaging_threads')
            ->select('id')
            ->orderBy('id')
            ->chunk(500, function ($threads): void {
                foreach ($threads as $thread) {
                    $latest = DB::table('messaging_messages')
                        ->where('thread_id', $thread->id)
                        ->whereNull('deleted_at')
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->value('id');

                    DB::table('messaging_threads')
                        ->where('id', $thread->id)
                        ->update(['last_message_id' => $latest]);
                }
            });
    }
};
