<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RoundlyConsulting\Messages\Exceptions\ThreadContextException;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\UlidCompany;
use RoundlyConsulting\Messages\Tests\Models\UlidUser;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/*
 * The thread context on a host keyed with ULIDs everywhere (`messages.key_type` and
 * `messages.primary_key_type` both `ulid` — the Opernity shape). `context_id` is an outbound
 * morph key, so it follows `key_type`.
 */

beforeEach(function (): void {
    config()->set('messages.permissions.enabled', false);

    $this->alice = UlidUser::create();
    $this->bob = UlidUser::create();
    $this->acme = UlidCompany::create();
    $this->globex = UlidCompany::create();
});

it('keys context_id as a ulid string column', function (): void {
    expect(Schema::getColumnType('messaging_threads', 'context_id'))->not->toBeIn(['integer', 'bigint', 'int8'])
        ->and(Str::isUlid((string) $this->acme->getKey()))->toBeTrue();

    $thread = Messages::start('Deal')->for($this->acme)->withParticipant($this->alice)->create()->fresh();

    expect($thread?->context_id)->toBe($this->acme->getKey())
        ->and(Str::isUlid((string) $thread?->getKey()))->toBeTrue()
        ->and($thread?->context?->is($this->acme))->toBeTrue();
});

it('lists only each ulid context\'s threads for the same participants', function (): void {
    $acme = Messages::start('Acme')->for($this->acme)->withParticipants([$this->alice, $this->bob])->create();
    $globex = Messages::start('Globex')->for($this->globex)->withParticipants([$this->alice, $this->bob])->create();
    Messages::send($acme, $this->alice, 'acme');
    Messages::send($globex, $this->alice, 'globex');
    Messages::send($globex, $this->alice, 'globex again');

    expect(collect(Messages::inboxFor($this->bob, context: $this->acme)->items())->map->getKey()->all())->toBe([$acme->getKey()])
        ->and(collect(Messages::inboxFor($this->bob, context: $this->globex)->items())->map->getKey()->all())->toBe([$globex->getKey()])
        ->and($this->bob->threads($this->acme)->map->getKey()->all())->toBe([$acme->getKey()])
        ->and(Messages::unreadCount($this->bob, context: $this->globex))->toBe(2)
        ->and(Messages::inboxFor($this->bob)->total())->toBe(2);
});

it('keeps one ulid direct thread per pair per context', function (): void {
    $acme = Messages::direct($this->alice, $this->bob, $this->acme);
    $globex = Messages::direct($this->alice, $this->bob, $this->globex);

    expect($acme->getKey())->not->toBe($globex->getKey())
        ->and(Messages::direct($this->bob, $this->alice, $this->acme)->getKey())->toBe($acme->getKey())
        ->and(Messages::direct($this->bob, $this->alice)->isInContext(null))->toBeTrue();
});

it('fails closed on ulid contexts', function (): void {
    $acme = Messages::start('Acme')->for($this->acme)->withParticipants([$this->alice, $this->bob])->create();
    $globex = Messages::start('Globex')->for($this->globex)->withParticipants([$this->alice, $this->bob])->create();

    Messages::resolveContextUsing(fn (): UlidCompany => $this->acme);

    expect(Thread::query()->pluck('id')->all())->toBe([$acme->getKey()])
        ->and(Thread::find($globex->getKey()))->toBeNull()
        ->and(fn () => Messages::send($globex, $this->alice, 'leak'))->toThrow(ThreadContextException::class)
        ->and(Messages::send($acme, $this->alice, 'fine')->thread_id)->toBe($acme->getKey());
});

it('renders context_id as a nullable fixed-length ulid column on postgres', function (): void {
    /** @var list<object{data_type: string, is_nullable: string}> $rows */
    $rows = DB::select(
        'select data_type, is_nullable from information_schema.columns where table_name = ? and column_name = ?',
        ['messaging_threads', 'context_id'],
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->data_type)->toBe('character')
        ->and($rows[0]->is_nullable)->toBe('YES');
})->skip(fn (): bool => DriverMatrix::driver() !== 'pgsql', 'needs the postgres catalog to tell the key types apart');
