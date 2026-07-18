<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\MessagesServiceProvider;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Messages ships three CREATEs and eight ALTERs, with three real foreign keys:
 * `messaging_messages.thread_id` and `messaging_participants.thread_id` onto
 * `messaging_threads`, plus the self-referencing `messaging_messages.parent_message_id`
 * added by an ALTER. Publishing preserves the source order, so that order has to be
 * runnable end to end.
 *
 * This file replaces ~130 lines of hand-rolled migration machinery (a bespoke published-copy
 * harness, a hand-built sqlite connection, and string-position assertions). The presets
 * cover the same ground, pinned so they cannot pass over an empty parse — and the `R` half
 * runs it against an engine that actually enforces the constraints, which the hand-rolled
 * version could never do: it built its own SQLite connection.
 */
$migrations = __DIR__.'/../../database/migrations';

/**
 * M — the structural pin. Five packages shipped uninstallable migration orders under green
 * SQLite suites. `foreignKeys: 3` pins the edge count so the check can never pass over an
 * empty parse.
 *
 * M also pins the other, non-FK half `MigrationGraph` checks: that every `Schema::table()`
 * ALTER sorts at or after the CREATE of the table it alters. Messages ships eight ALTERs —
 * the most in the fleet outside shops — so that half is doing real work here. Before these
 * files were renamed, `create_messaging_messages_table` sorted FIRST and
 * `create_messaging_threads_table` third: unrunnable on any engine that enforces foreign
 * keys at DDL time.
 */
it('creates every table before the migrations that reference or alter it', function () use ($migrations): void {
    expect($migrations)->toHaveRunnableMigrationOrder(foreignKeys: 3);
});

/**
 * P — the publish-only guards. The fleet publishes migrations timestamped rather than
 * auto-loading them; doing both runs both copies and dies on a duplicate table (bug #5, on
 * three packages). `11` pins the file count so neither check can pass over an empty or
 * relocated directory.
 */
it('never auto-loads its migrations — the host publishes them', function (): void {
    expect(MessagesServiceProvider::class)->toNotAutoLoadMigrations();
});

it('publishes its migrations timestamp-injected into the host', function (): void {
    expect(MessagesServiceProvider::class)->toPublishMigrationsTimestamped('messages-migrations', 11);
});

/**
 * R — the behavioural half, on an engine that can actually refuse. Gated on reachability so
 * it skips *visibly* off the pgsql leg rather than passing vacuously.
 *
 * `migrations: 11` pins the count, and the assertion fails hard if a set "applies cleanly"
 * while creating no tables — an empty `up()` would otherwise pass and prove nothing.
 */
it('applies the published order cleanly on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 11);
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'pgsql connection not available');

/**
 * The negative control. It fits here — unlike a 0-FK row, where reversing the file list
 * leaves Postgres nothing to refuse and the control fails by design. Messages has three real
 * FK edges, so a reversed order puts children before parents and Postgres genuinely rejects
 * it. If this ever goes green-by-acceptance the assertion says so loudly.
 */
it('is refused by postgres when the order is broken', function () use ($migrations): void {
    expect($migrations)->toRejectBrokenOrderOnConnection(
        fn (array $files): array => array_reverse($files),
        'pgsql',
    );
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'pgsql connection not available');

/**
 * The driver-truth pin: the env-declared driver against what the connection itself answers.
 * It makes a lying pgsql leg impossible — a base case decapitated by an un-parented
 * `defineEnvironment()` override goes red here instead of quietly running SQLite and
 * reporting itself green. It fires automatically rather than needing a human to read a skip
 * count.
 */
it('runs on the driver the environment declared', function (): void {
    expect(DatabaseDriver::current())->toBe(DatabaseDriver::from(DriverMatrix::driver()));
});
