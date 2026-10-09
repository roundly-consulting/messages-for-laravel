<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Messages\Actions\FindOrCreateDirectThread;
use RoundlyConsulting\Messages\Actions\StartThread;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Fixtures\QueryRecorder;
use RoundlyConsulting\Messages\Tests\Models\Company;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * Two first contacts racing each other used to create two DMs: both missed `between()`, both
 * inserted. The pair now carries a unique key (`direct_key`), so the database refuses the
 * second insert and the loser returns the winner's thread.
 */
beforeEach(function () {
    $this->alice = User::create();
    $this->bob = User::create();
});

/**
 * Replays the interleaving deterministically: the moment this request's `between()` lookup has
 * missed, a "concurrent" request creates the DM. Our request then inserts a duplicate.
 */
function racedByAConcurrentFirstContact(Closure $concurrent): void
{
    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced, $concurrent): void {
        if ($raced || ! str_contains($query->sql, 'is_direct') || ! str_starts_with(strtolower($query->sql), 'select')) {
            return;
        }

        $raced = true;
        $concurrent();
    });
}

it('returns the concurrent winner instead of creating a second DM', function () {
    $winner = null;

    racedByAConcurrentFirstContact(function () use (&$winner): void {
        $winner = app(FindOrCreateDirectThread::class)->execute($this->bob, $this->alice);
    });

    $thread = Messages::direct($this->alice, $this->bob);

    expect($winner)->toBeInstanceOf(Thread::class)
        ->and($thread->getKey())->toBe($winner->getKey())
        ->and(Thread::query()->withTrashed()->where('is_direct', true)->count())->toBe(1)
        ->and($thread->participants()->count())->toBe(2);
});

it('lets the database refuse a second keyed DM for the same pair', function () {
    $dm = $this->alice->conversationWith($this->bob);

    expect(fn () => app(StartThread::class)->execute(new CreateThreadData(
        isDirect: true,
        participants: [$this->bob, $this->alice],
        directKey: $dm->direct_key,
    )))->toThrow(UniqueConstraintViolationException::class);

    expect(Thread::query()->withTrashed()->count())->toBe(1);
});

it('keys a pair the same way whichever side starts', function () {
    $company = Company::create();

    $dm = Messages::direct($this->alice, $company);

    $side = static fn ($model): string => strlen($model->getMorphClass()).':'.$model->getMorphClass().':'.$model->getKey();
    $sides = [$side($this->alice), $side($company)];
    sort($sides);

    expect($dm->direct_key)->toBe(Thread::directKeyFor($company, $this->alice))
        ->and($dm->direct_key)->toBe(implode('|', $sides))
        ->and(Thread::directKeyFor($this->alice, $this->bob))
        ->not->toBe(Thread::directKeyFor($this->alice, $company))
        ->and(Messages::direct($company, $this->alice)->getKey())->toBe($dm->getKey());
});

it('keys a note-to-self thread on its own', function () {
    $self = $this->alice->conversationWith($this->alice);

    expect($self->direct_key)->toBe(Thread::directKeyFor($this->alice, $this->alice))
        ->and($this->alice->conversationWith($this->alice)->getKey())->toBe($self->getKey())
        ->and($this->alice->conversationWith($this->bob)->getKey())->not->toBe($self->getKey());
});

it('takes the key over from a deleted DM and starts a fresh one', function () {
    $old = $this->alice->conversationWith($this->bob);
    $old->delete();

    $fresh = $this->alice->conversationWith($this->bob);

    expect($fresh->getKey())->not->toBe($old->getKey())
        ->and($fresh->direct_key)->toBe(Thread::directKeyFor($this->alice, $this->bob))
        ->and(Thread::withTrashed()->find($old->getKey())->direct_key)->toBeNull()
        ->and($this->bob->conversationWith($this->alice)->getKey())->toBe($fresh->getKey());
});

it('takes the key over from a DM someone has left', function () {
    $old = $this->alice->conversationWith($this->bob);
    Messages::thread($old)->participants()->leave($this->bob);

    $fresh = $this->alice->conversationWith($this->bob);

    expect($fresh->getKey())->not->toBe($old->getKey())
        ->and($old->fresh()->direct_key)->toBeNull()
        ->and($fresh->participants()->count())->toBe(2);
});

it('recovers when a concurrent request takes over a stale key first', function () {
    $stale = $this->alice->conversationWith($this->bob);
    $stale->delete();

    $winner = null;

    racedByAConcurrentFirstContact(function () use (&$winner): void {
        $winner = app(FindOrCreateDirectThread::class)->execute($this->bob, $this->alice);
    });

    $thread = Messages::direct($this->alice, $this->bob);

    expect($thread->getKey())->toBe($winner->getKey())
        ->and(Thread::query()->where('is_direct', true)->count())->toBe(1);
});

it('leaves group threads unkeyed', function () {
    $group = Messages::start('Pair')->withParticipants([$this->alice, $this->bob])->create();

    expect($group->direct_key)->toBeNull()
        ->and(Messages::start('Another pair')->withParticipants([$this->alice, $this->bob])->create()->direct_key)->toBeNull();
});

/**
 * The builder made direct threads without the pair key: `start()->direct()->withParticipants()`
 * beside an existing DM gave the pair a second, unkeyed one, `direct()` then returned whichever
 * the engine happened to list first, and a third participant made a three-person "direct"
 * thread. Builder DMs of one or two participants now carry the key, more are refused, and the
 * lookup is ordered.
 */
describe('a direct thread from the builder', function () {
    it('carries the pair key', function () {
        $dm = Messages::start()->direct()->withParticipants([$this->alice, $this->bob])->create();

        expect($dm->direct_key)->toBe(Thread::directKeyFor($this->alice, $this->bob))
            ->and(Messages::direct($this->bob, $this->alice)->getKey())->toBe($dm->getKey());
    });

    it('keys a note-to-self, however often the one side is listed', function (array $sides) {
        $self = Messages::start()->direct()->withParticipants(array_map(fn (string $side) => $this->{$side}, $sides))->create();

        expect($self->direct_key)->toBe(Thread::directKeyFor($this->alice, $this->alice))
            ->and($self->participants()->count())->toBe(1);
    })->with([
        'once' => [['alice']],
        'twice' => [['alice', 'alice']],
    ]);

    it('refuses a second DM for a pair that has one', function () {
        $first = Messages::direct($this->alice, $this->bob);

        expect(fn () => Messages::start()->direct()->withParticipants([$this->bob, $this->alice])->create())
            ->toThrow(UniqueConstraintViolationException::class);

        expect(Thread::query()->withTrashed()->where('is_direct', true)->count())->toBe(1)
            ->and(Messages::direct($this->alice, $this->bob)->getKey())->toBe($first->getKey());
    });

    it('refuses more than two participants before writing anything', function () {
        $carol = User::create();

        expect(fn () => Messages::start()->direct()->withParticipants([$this->alice, $this->bob, $carol])->create())
            ->toThrow(ParticipationException::class, ParticipationException::directThreadTakesTwo()->getMessage());

        expect(Thread::query()->withTrashed()->count())->toBe(0);
    });

    it('leaves a direct thread with no participants unkeyed', function () {
        expect(Messages::start()->direct()->create()->direct_key)->toBeNull();
    });
});

it('orders the direct-thread lookup', function () {
    Messages::direct($this->alice, $this->bob);

    $log = QueryRecorder::during(fn () => Messages::direct($this->alice, $this->bob));

    $lookup = QueryRecorder::first($log, static fn (string $sql): bool => str_starts_with($sql, 'select')
        && str_contains($sql, 'from messaging_threads ')
        && str_contains($sql, 'is_direct'));

    expect($lookup)->not->toBeNull()
        ->and($log[(int) $lookup]['sql'])->toContain('order by');
});
