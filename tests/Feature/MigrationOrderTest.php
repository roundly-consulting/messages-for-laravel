<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Messages\MessagesServiceProvider;

/**
 * The package ships three CREATEs and six ALTERs, and two of the CREATEs carry a
 * real foreign key onto `messaging_threads`. Publishing preserves the source
 * directory's order, so that order has to be runnable end to end: every table
 * must exist before anything references or alters it.
 *
 * Before the sources were renamed, the directory sorted
 * `create_messaging_messages_table` (FK → messaging_threads) FIRST and
 * `create_messaging_threads_table` third — unrunnable on any engine that enforces
 * foreign keys at DDL time. SQLite happily creates a table referencing a missing
 * parent, which is exactly why the suite never caught it; PostgreSQL and MySQL do
 * not.
 *
 * These tests run the *published* files — under their published names, into a
 * database that starts empty — which is what a host actually does.
 */
beforeEach(function (): void {
    $this->publishedPath = sys_get_temp_dir().'/messages-migration-order-'.bin2hex(random_bytes(6));
    $this->publishedDatabase = $this->publishedPath.'/database.sqlite';

    File::makeDirectory($this->publishedPath, recursive: true);
    File::put($this->publishedDatabase, '');

    // Copy every source to the filename it publishes under, so the migrator sees
    // precisely what lands in a host's database/migrations directory.
    foreach (ServiceProvider::pathsToPublish(MessagesServiceProvider::class, 'messages-migrations') as $source => $target) {
        File::copy($source, $this->publishedPath.'/'.basename((string) $target));
    }

    config()->set('database.connections.published', [
        'driver' => 'sqlite',
        'database' => $this->publishedDatabase,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->publishedPath);
});

it('migrates the published files clean from an empty database', function (): void {
    $schema = Schema::connection('published');

    expect($schema->hasTable('messaging_threads'))->toBeFalse();

    $this->artisan('migrate', [
        '--database' => 'published',
        '--path' => $this->publishedPath,
        '--realpath' => true,
    ])->assertExitCode(0);

    expect($schema->hasTable('messaging_threads'))->toBeTrue()
        ->and($schema->hasTable('messaging_messages'))->toBeTrue()
        ->and($schema->hasTable('messaging_participants'))->toBeTrue();

    // Every ALTER ran against a table that already existed.
    expect($schema->hasColumns('messaging_messages', ['parent_message_id', 'type', 'meta']))->toBeTrue()
        ->and($schema->hasColumns('messaging_participants', ['last_read_message_id', 'role']))->toBeTrue()
        ->and($schema->hasColumns('messaging_threads', ['archived_at', 'is_direct']))->toBeTrue();
});

it('keeps every foreign key intact in the published schema', function (): void {
    $this->artisan('migrate', [
        '--database' => 'published',
        '--path' => $this->publishedPath,
        '--realpath' => true,
    ])->assertExitCode(0);

    $schema = Schema::connection('published');

    $references = static fn (string $table): array => array_map(
        static fn (array $key): string => (string) $key['foreign_table'],
        $schema->getForeignKeys($table),
    );

    // Both child tables really do constrain onto the threads table — so the CREATE
    // order is load-bearing, not incidental.
    expect($references('messaging_messages'))->toContain('messaging_threads')
        ->and($references('messaging_participants'))->toContain('messaging_threads');
});

it('publishes every migration under a name that sorts after the table it depends on', function (): void {
    $published = array_map(
        static fn (string $target): string => basename($target),
        array_values(ServiceProvider::pathsToPublish(MessagesServiceProvider::class, 'messages-migrations')),
    );

    $position = static function (string $needle) use ($published): int {
        foreach ($published as $index => $name) {
            if (str_contains($name, $needle)) {
                return $index;
            }
        }

        return -1;
    };

    $threads = $position('create_messaging_threads_table');
    $messages = $position('create_messaging_messages_table');
    $participants = $position('create_messaging_participants_table');

    // Foreign-key targets are created before the tables that reference them.
    expect($threads)->toBeLessThan($messages)
        ->and($threads)->toBeLessThan($participants);

    // Each ALTER sorts after the CREATE of the table it alters.
    expect($messages)->toBeLessThan($position('update_messaging_messages_table_with_parent'))
        ->and($messages)->toBeLessThan($position('update_messaging_messages_table_with_type_and_meta'))
        ->and($participants)->toBeLessThan($position('update_messaging_participants_table_with_last_read'))
        ->and($participants)->toBeLessThan($position('update_messaging_participants_table_with_role'))
        ->and($threads)->toBeLessThan($position('update_messaging_threads_table_with_archived_at'))
        ->and($threads)->toBeLessThan($position('update_messaging_threads_table_with_direct_support'));
});

it('publishes timestamps that preserve the dependency order', function (): void {
    $destinations = array_map(
        static fn (string $target): string => basename($target),
        array_values(ServiceProvider::pathsToPublish(MessagesServiceProvider::class, 'messages-migrations')),
    );

    expect($destinations)->toHaveCount(9);

    $sorted = $destinations;
    sort($sorted);

    // A host's migrator runs its database/migrations directory in filename order, so
    // the published filenames must already sort into the dependency order.
    expect($sorted)->toBe($destinations);

    foreach ($destinations as $destination) {
        expect($destination)->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_\d{4}_(create|update)_messaging_\w+\.php$/');
    }
});
