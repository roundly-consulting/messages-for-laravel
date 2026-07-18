<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index `messaging_messages` on the way it is actually read: by thread, newest first.
 *
 * `thread_id` carried NO index. It is a foreign key, and Laravel's `foreignId()` creates the
 * column and the constraint but not an index — and PostgreSQL, unlike MySQL/InnoDB, does not
 * create one for a referencing column either. So every read that narrows to a thread was a
 * sequential scan of the whole table: `messages()`, `unreadCountFor()`, the unread counts on
 * the inbox, `PruneMessages` scoped to a thread, and the cascade check when a thread is
 * deleted. Confirmed on Postgres — `Seq Scan on messaging_messages ... rows=100000` for a
 * query wanting one thread's messages.
 *
 * The column order matches the sort the package asks for everywhere: `thread_id` equality,
 * then `created_at` desc, then `id` desc. The index is ascending because Laravel's Blueprint
 * has no portable per-column direction, which costs nothing here — a btree scans backwards
 * just as cheaply, so `order by created_at desc, id desc` still resolves from the index with
 * no sort step.
 *
 * This became load-bearing with 0010: the latest-message pointer is recomputed on every send,
 * unsend, restore and prune, and each recompute is exactly this lookup. Without the index the
 * denormalisation would trade a slow read for a slow write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messaging_messages', function (Blueprint $table): void {
            $table->index(['thread_id', 'created_at', 'id'], 'messaging_messages_thread_latest_index');
        });
    }
};
