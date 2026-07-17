<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * The two-axes independence proof, outbound half.
 *
 * This leg boots with ONLY `messages.key_type = uuid` (via {@see MorphUuidKeyTestCase}); the
 * inbound `messages.primary_key_type` is left at its `bigint` default. The sender and
 * participant morph columns are the package's *outbound* pointers — the models it points at —
 * and they must follow `key_type`, while the package's own ids and its four internal foreign
 * keys must stay bigint. If the two axes ever bled into one another this leg would fail: a
 * uuid morph column would drag the bigint pk along, or vice versa.
 */

/** @return array{type: string, nullable: string} */
function morphPgColumn(string $table, string $column): array
{
    /** @var list<object{data_type: string, is_nullable: string}> $rows */
    $rows = DB::select(
        'select data_type, is_nullable from information_schema.columns where table_name = ? and column_name = ?',
        [$table, $column],
    );

    $row = $rows[0] ?? null;

    return $row === null
        ? ['type' => 'MISSING', 'nullable' => 'MISSING']
        : ['type' => $row->data_type, 'nullable' => $row->is_nullable];
}

$integerish = ['integer', 'bigint', 'int8'];
$stringish = ['varchar', 'string', 'uuid', 'char'];

it('flips the outbound morph ids to uuid while the pk and internal fks stay bigint', function () use ($integerish, $stringish): void {
    // Inbound axis — untouched by key_type. The package's own ids and every internal FK
    // stay bigint even though the morph columns were flipped to uuid.
    expect(Schema::getColumnType('messaging_threads', 'id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_messages', 'id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_participants', 'id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_messages', 'thread_id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_messages', 'parent_message_id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_participants', 'thread_id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_participants', 'last_read_message_id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_threads', 'last_message_id'))->toBeIn($integerish);

    // Outbound axis — flipped to uuid. Not integer, so a bled pk cannot pass here.
    expect(Schema::getColumnType('messaging_messages', 'sender_id'))->toBeIn($stringish)
        ->and(Schema::getColumnType('messaging_messages', 'sender_id'))->not->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_participants', 'participant_id'))->toBeIn($stringish)
        ->and(Schema::getColumnType('messaging_participants', 'participant_id'))->not->toBeIn($integerish);
});

/**
 * The exact answer, on the only engine that can tell uuid from bigint apart. The morph ids
 * are uuid; the pk and a representative internal FK are bigint; and the `sender` morph keeps
 * the nullability of the `nullableMorphs()` it replaced while `participant` stays NOT NULL.
 */
it('renders the two axes as distinct real column types on postgres', function (): void {
    expect(morphPgColumn('messaging_messages', 'sender_id'))->toBe(['type' => 'uuid', 'nullable' => 'YES'])
        ->and(morphPgColumn('messaging_participants', 'participant_id'))->toBe(['type' => 'uuid', 'nullable' => 'NO'])
        ->and(morphPgColumn('messaging_messages', 'id')['type'])->toBe('bigint')
        ->and(morphPgColumn('messaging_messages', 'thread_id')['type'])->toBe('bigint')
        ->and(morphPgColumn('messaging_messages', 'sender_type')['type'])->toBe('character varying');
})->skip(fn (): bool => DriverMatrix::driver() !== 'pgsql', 'needs the postgres catalog to tell the key types apart');
